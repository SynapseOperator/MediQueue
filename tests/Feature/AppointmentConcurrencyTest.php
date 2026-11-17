<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AppointmentConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $inputs = [];

    private string $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        // Commit fixtures so every independent worker sees the same real database.
        // The seeder rejects existing core rows; TestCase verifies mediqueue_test first.
        $this->seed();
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::transaction(function (): void {
                foreach (['audit_logs', 'refunds', 'payment_orders', 'appointments', 'slots', 'schedules', 'doctors', 'departments', 'patients', 'users'] as $table) {
                    DB::table($table)->delete();
                }
            });
        });
    }

    public function test_twenty_independent_patients_compete_for_the_last_seat(): void
    {
        $this->prepareEvidence('last-seat');
        $now = now('UTC')->format('Y-m-d H:i:s.v');
        for ($number = 4; $number <= 20; $number++) {
            $userId = DB::table('users')->insertGetId([
                'username' => 'concurrent_patient_'.$number,
                'password_hash' => Hash::make(bin2hex(random_bytes(24))),
                'role' => 'PATIENT', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('patients')->insert([
                'user_id' => $userId, 'full_name' => '合成并发患者'.$number,
                'phone' => 'TEST-CONCURRENT-'.$number, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $patients = DB::table('patients')->orderBy('id')->get();
        $this->assertCount(20, $patients);
        $slot = DB::table('slots')->orderBy('id')->first();
        DB::table('slots')->where('id', $slot->id)->update(['capacity' => 1]);
        $departmentId = DB::table('schedules')->where('id', $slot->schedule_id)->value('department_id');
        $jobs = $patients->map(fn ($patient) => [
            'operation' => 'appointment', 'user_id' => $patient->user_id,
            'slot_id' => $slot->id, 'request_key' => 'last-seat-'.$patient->id,
        ])->all();

        $results = $this->raceTogether($jobs, 'departments', $departmentId);
        $this->assertCount(20, array_unique(array_column($results, 'user_id')));
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['outcome'] === 'created')));
        $this->assertSame(19, count(array_filter($results, fn ($result) => $result['outcome'] === 'rejected')));
        $state = $this->assertLedger(1);
        $this->assertSame(1, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
        $this->saveEvidence($results, $state);
    }

    public function test_same_patient_cannot_concurrently_book_overlapping_different_doctors(): void
    {
        $this->prepareEvidence('same-patient');
        $patient = DB::table('patients')->orderBy('id')->first();
        $schedules = DB::table('schedules')->orderBy('id')->get();
        $this->assertNotSame($schedules[0]->doctor_id, $schedules[1]->doctor_id);
        $this->assertNotSame($schedules[0]->department_id, $schedules[1]->department_id);
        $slots = $schedules->map(fn ($schedule) => DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('starts_at')->first());
        $this->assertSame($slots[0]->starts_at, $slots[1]->starts_at);
        $jobs = $slots->map(fn ($slot) => [
            'operation' => 'appointment', 'user_id' => $patient->user_id,
            'slot_id' => $slot->id, 'request_key' => 'cross-doctor-'.$slot->id,
        ])->all();

        // Separate departments avoid a shared hospital resource accidentally replacing the patient mutex.
        $results = $this->raceTogether($jobs, 'patients', $patient->id);
        $this->assertSame([$patient->user_id], array_values(array_unique(array_column($results, 'user_id'))));
        $this->assertEqualsCanonicalizing(['created', 'rejected'], array_column($results, 'outcome'));
        $state = $this->assertLedger(1);
        $this->assertSame($patient->id, DB::table('appointments')->sole()->patient_id);
        $this->saveEvidence($results, $state);
    }

    #[DataProvider('firstWriters')]
    public function test_suspension_and_booking_follow_the_protected_commit_order(string $first): void
    {
        $this->prepareEvidence($first === 'suspend' ? 'suspension-first' : 'appointment-first');
        $schedule = DB::table('schedules')->orderBy('id')->first();
        $slot = DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('id')->first();
        $appointment = [
            'operation' => 'appointment', 'user_id' => DB::table('patients')->orderBy('id')->value('user_id'),
            'slot_id' => $slot->id, 'request_key' => 'suspension-race',
        ];
        $suspension = [
            'operation' => 'suspend', 'user_id' => DB::table('users')->where('role', 'ADMIN')->value('id'),
            'schedule_id' => $schedule->id,
            'input' => [
                'doctor_id' => $schedule->doctor_id, 'work_date' => $schedule->work_date,
                'session_code' => $schedule->session_code,
                'starts_at' => CarbonImmutable::parse($schedule->starts_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i'),
                'ends_at' => CarbonImmutable::parse($schedule->ends_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i'),
                'status' => 'SUSPENDED', 'suspend_reason' => '合成并发停诊验证',
            ],
        ];
        $jobs = $first === 'suspend' ? [$suspension, $appointment] : [$appointment, $suspension];
        $jobs[0]['pause_after_lock'] = true;
        $this->startWorkers($jobs, 'departments');
        $this->awaitPhase('ready', [0, 1]);
        $this->signal(0, "GO\n");
        // QueryExecuted pauses the leader after the actual database lock is acquired.
        $this->awaitPhase('holding', [0]);
        $this->signal(1, "GO\n", true);
        $this->awaitPhase('locking', [1]);
        $this->signal(0, "RELEASE\n", true);
        $results = $this->finishWorkers();

        $this->assertSame($first === 'suspend' ? 'suspended' : 'created', $results[0]['outcome']);
        $this->assertSame($first === 'suspend' ? 'rejected' : 'suspended', $results[1]['outcome']);
        $expectedAppointments = $first === 'suspend' ? 0 : 1;
        $state = $this->assertLedger($expectedAppointments, 1);
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'SUSPENDED']);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'status' => 'OPEN', 'occupied_count' => $expectedAppointments]);
        if ($first === 'appointment') {
            $createdAudit = DB::table('audit_logs')->where('action', 'APPOINTMENT_CREATED')->sole();
            $suspendedAudit = DB::table('audit_logs')->where('action', 'SCHEDULE_SAVED')->sole();
            $this->assertLessThan($suspendedAudit->id, $createdAudit->id);
        }
        $this->saveEvidence($results, $state);
    }

    public static function firstWriters(): array
    {
        return ['suspension first' => ['suspend'], 'appointment first' => ['appointment']];
    }

    private function raceTogether(array $jobs, string $table, int $id): array
    {
        DB::beginTransaction();
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
        try {
            $this->startWorkers($jobs, $table);
            $indices = array_keys($this->workers);
            $this->awaitPhase('ready', $indices);
            foreach ($indices as $index) {
                $this->signal($index, "GO\n", true);
            }
            $this->awaitPhase('locking', $indices);
        } finally {
            DB::rollBack();
        }

        return $this->finishWorkers();
    }

    private function startWorkers(array $jobs, string $gateTable): void
    {
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => 'mediqueue_test', 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        ];
        foreach ($jobs as $job) {
            $input = new InputStream;
            $worker = new Process([
                PHP_BINARY, base_path('tests/Support/appointment-worker.php'),
                json_encode($job + ['gate_table' => $gateTable], JSON_THROW_ON_ERROR),
            ], base_path(), $environment, $input, 60);
            $this->inputs[] = $input;
            $this->workers[] = $worker;
            $worker->start();
        }
    }

    private function signal(int $index, string $message, bool $close = false): void
    {
        $this->inputs[$index]->write($message);
        if ($close) {
            $this->inputs[$index]->close();
        }
    }

    private function awaitPhase(string $phase, array $indices): array
    {
        $deadline = microtime(true) + 40;
        do {
            $matched = [];
            foreach ($indices as $index) {
                $worker = $this->workers[$index];
                $events = array_values(array_filter($this->events($worker), fn ($event) => $event['phase'] === $phase));
                if ($events !== []) {
                    $matched[] = $events[0];
                } elseif (! $worker->isRunning()) {
                    $this->fail('Worker exited before '.$phase.': '.$worker->getErrorOutput().' '.$worker->getOutput());
                }
            }
            if (count($matched) === count($indices)) {
                return $matched;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for independent workers to reach '.$phase.'.');
    }

    private function events(Process $worker): array
    {
        $events = [];
        foreach (explode("\n", $worker->getOutput()) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && isset($event['phase'])) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function finishWorkers(): array
    {
        $results = [];
        foreach ($this->workers as $worker) {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $events = array_values(array_filter($this->events($worker), fn ($event) => $event['phase'] === 'result'));
            $this->assertCount(1, $events, $worker->getOutput());
            $result = $events[0];
            if ($result['outcome'] === 'rejected') {
                // A retry-exhaustion request_key error must not masquerade as a business loser.
                $this->assertSame(['slot_id'], $result['fields']);
            }
            $results[] = $result;
        }
        $this->assertCount(count($results), array_unique(array_column($results, 'pid')));
        $this->assertCount(count($results), array_unique(array_column($results, 'connection_id')));
        $this->assertNotContains((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, array_column($results, 'connection_id'));

        return $results;
    }

    private function assertLedger(int $expectedAppointments, int $managementAudits = 0): array
    {
        $this->assertSame($expectedAppointments, DB::table('appointments')->count());
        $this->assertSame($expectedAppointments, DB::table('payment_orders')->count());
        $this->assertSame($expectedAppointments, DB::table('audit_logs')->where('action', 'APPOINTMENT_CREATED')->count());
        $this->assertSame($expectedAppointments + $managementAudits, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('refunds')->count());
        foreach (DB::table('appointments')->get() as $appointment) {
            $slot = DB::table('slots')->find($appointment->slot_id);
            $order = DB::table('payment_orders')->where('appointment_id', $appointment->id)->sole();
            $audit = DB::table('audit_logs')->where('action', 'APPOINTMENT_CREATED')->where('target_id', $appointment->id)->sole();
            $this->assertSame('PENDING_PAYMENT', $appointment->status);
            $this->assertNull($appointment->released_at);
            $this->assertSame('UNPAID', $order->status);
            $this->assertSame('MOCK_WECHAT', $order->channel);
            $this->assertSame($appointment->fee_amount, $order->amount);
            $this->assertSame($slot->fee_amount, $appointment->fee_amount);
            $this->assertSame('appointments', $audit->target_type);
            $this->assertSame(DB::table('patients')->where('id', $appointment->patient_id)->value('user_id'), $audit->operator_id);
            $expires = min(CarbonImmutable::parse($appointment->created_at, 'UTC')->addMinutes(15)->format('Y-m-d H:i:s.v'), $slot->starts_at);
            $this->assertSame($expires, $appointment->expires_at);
        }
        // One SQL statement compares stored occupancy with its actual consuming records.
        $slots = DB::select("SELECT s.id, s.capacity, s.occupied_count, COALESCE(a.actual, 0) AS actual FROM slots s LEFT JOIN (SELECT slot_id, COUNT(*) AS actual FROM appointments WHERE status IN ('PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED') GROUP BY slot_id) a ON a.slot_id = s.id ORDER BY s.id");
        foreach ($slots as $slot) {
            $this->assertSame((int) $slot->actual, $slot->occupied_count);
            $this->assertGreaterThanOrEqual(0, $slot->occupied_count);
            $this->assertLessThanOrEqual($slot->capacity, $slot->occupied_count);
        }

        return ['appointments' => $expectedAppointments, 'payment_orders' => $expectedAppointments,
            'appointment_audits' => $expectedAppointments, 'management_audits' => $managementAudits, 'slots' => $slots];
    }

    private function prepareEvidence(string $scenario): void
    {
        $this->scenario = $scenario;
        $path = base_path('test-results/appointment-concurrency-'.$scenario.'.json');
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function saveEvidence(array $results, array $state): void
    {
        $directory = base_path('test-results');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $evidence = ['scenario' => $this->scenario, 'database' => 'mediqueue_test',
            'isolation' => DB::selectOne('SELECT @@transaction_isolation AS value')->value,
            'participants' => count($results), 'workers' => $results, 'final_state' => $state];
        $this->assertNotFalse(file_put_contents($directory.'/appointment-concurrency-'.$this->scenario.'.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"));
    }
}
