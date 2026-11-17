<?php

namespace Tests\Feature;

use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LifecycleConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $inputs = [];

    private int $patientId;

    private int $userId;

    private int $adminId;

    private int $slotId;

    private int $appointmentId;

    private string $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mediqueue.mock_payments_enabled' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:30:00.123Z'));
        // Committed synthetic fixtures are visible to every independent PHP/PDO process.
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
            CarbonImmutable::setTestNow();
        });
        $patient = DB::table('patients')->orderBy('id')->first();
        $this->patientId = $patient->id;
        $this->userId = $patient->user_id;
        $this->adminId = DB::table('users')->where('role', 'ADMIN')->value('id');
        $this->slotId = DB::table('slots')->orderBy('id')->value('id');
        $this->appointmentId = app(AppointmentService::class)->create($this->userId, $this->slotId, 'lifecycle-concurrency');
    }

    public function test_duplicate_payments_confirm_only_once_without_occupying_again(): void
    {
        $this->prepareEvidence('duplicate-payment');
        $results = $this->raceTogether([['operation' => 'pay'], ['operation' => 'pay']]);
        $this->assertSame(['CONFIRMED', 'CONFIRMED'], array_column($results, 'result'));
        $this->assertAudits(['APPOINTMENT_CREATED' => 1, 'PAYMENT_CONFIRMED' => 1]);
        $state = $this->assertLedger('CONFIRMED', 'PAID');
        $before = DB::table('payment_orders')->sole();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->assertSame('CONFIRMED', app(AppointmentLifecycleService::class)->pay($this->userId, $this->appointmentId));
        $this->assertEquals($before, DB::table('payment_orders')->sole());
        $this->saveEvidence($results, $state);
    }

    public function test_payment_and_cancellation_preserve_any_payment_and_refund_obligation(): void
    {
        $this->prepareEvidence('payment-cancellation');
        $results = $this->raceTogether([['operation' => 'pay'], ['operation' => 'cancel']]);
        $this->assertContains($results[0]['result'], ['CONFIRMED', 'CANCELLED']);
        $this->assertSame('CANCELLED', $results[1]['result']);
        $paid = $results[0]['result'] === 'CONFIRMED';
        $this->assertAudits(['APPOINTMENT_CREATED' => 1, 'APPOINTMENT_CANCELLED' => 1, 'PAYMENT_CONFIRMED' => $paid ? 1 : 0]);
        $state = $this->assertLedger('CANCELLED', $paid ? 'REFUND_PENDING' : 'CLOSED', $paid ? 'PENDING' : null);
        $this->saveEvidence($results, $state);
    }

    #[DataProvider('expiryContenders')]
    public function test_payment_or_cancellation_and_expiry_at_exact_deadline_release_once(string $operation): void
    {
        $this->prepareEvidence($operation === 'pay' ? 'payment-expiry-deadline' : 'cancellation-expiry-deadline');
        CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('appointments')->sole()->expires_at, 'UTC'));
        $results = $this->raceTogether([['operation' => $operation], ['operation' => 'expire', 'user_id' => null]]);
        $this->assertSame('EXPIRED', $results[0]['result']);
        $this->assertIsBool($results[1]['result']);
        $this->assertAudits(['APPOINTMENT_CREATED' => 1, 'APPOINTMENT_EXPIRED' => 1]);
        $state = $this->assertLedger('EXPIRED', 'CLOSED');
        $this->saveEvidence($results, $state);
    }

    public static function expiryContenders(): array
    {
        return [['pay'], ['cancel']];
    }

    public function test_timer_and_on_demand_reclamation_share_one_transition_even_for_disabled_patient(): void
    {
        $this->prepareEvidence('two-reclaim-entrances');
        CarbonImmutable::setTestNow(CarbonImmutable::parse(DB::table('appointments')->sole()->expires_at, 'UTC'));
        DB::table('users')->where('id', $this->userId)->update(['status' => 'DISABLED']);
        $results = $this->raceTogether([
            ['operation' => 'reclaim-timer', 'user_id' => null],
            ['operation' => 'reclaim-on-demand', 'user_id' => null, 'patient_id' => $this->patientId, 'slot_id' => $this->slotId],
        ]);
        $this->assertEqualsCanonicalizing([0, 1], array_column($results, 'result'));
        $this->assertAudits(['APPOINTMENT_CREATED' => 1, 'APPOINTMENT_EXPIRED' => 1]);
        $state = $this->assertLedger('EXPIRED', 'CLOSED');
        $this->saveEvidence($results, $state);
    }

    public function test_duplicate_paid_cancellations_release_once_and_create_one_refund(): void
    {
        $this->prepareEvidence('duplicate-cancellation');
        app(AppointmentLifecycleService::class)->pay($this->userId, $this->appointmentId);
        $paidAt = DB::table('payment_orders')->sole()->paid_at;
        $results = $this->raceTogether([['operation' => 'cancel'], ['operation' => 'cancel']]);
        $this->assertSame(['CANCELLED', 'CANCELLED'], array_column($results, 'result'));
        $this->assertSame($paidAt, DB::table('payment_orders')->sole()->paid_at);
        $this->assertAudits(['APPOINTMENT_CREATED' => 1, 'PAYMENT_CONFIRMED' => 1, 'APPOINTMENT_CANCELLED' => 1]);
        $state = $this->assertLedger('CANCELLED', 'REFUND_PENDING', 'PENDING');
        $this->saveEvidence($results, $state);
    }

    public function test_success_and_failure_refund_retries_cannot_duplicate_or_reverse_success(): void
    {
        $this->prepareEvidence('refund-success-failure');
        $service = app(AppointmentLifecycleService::class);
        $service->pay($this->userId, $this->appointmentId);
        $service->cancel($this->userId, $this->appointmentId);
        $this->assertSame('FAILED', $service->refund($this->adminId, $this->appointmentId, 'failed'));
        $original = DB::table('refunds')->sole();
        $results = $this->raceTogether([
            ['operation' => 'refund', 'user_id' => $this->adminId, 'scenario' => 'success'],
            ['operation' => 'refund', 'user_id' => $this->adminId, 'scenario' => 'failed'],
        ]);
        $this->assertSame('SUCCEEDED', $results[0]['result']);
        $this->assertContains($results[1]['result'], ['FAILED', 'SUCCEEDED']);
        $this->assertAudits(['APPOINTMENT_CREATED' => 1, 'PAYMENT_CONFIRMED' => 1, 'APPOINTMENT_CANCELLED' => 1,
            'REFUND_SUCCEEDED' => 1, 'REFUND_FAILED' => $results[1]['result'] === 'FAILED' ? 2 : 1]);
        $state = $this->assertLedger('CANCELLED', 'REFUNDED', 'SUCCEEDED');
        $successful = DB::table('refunds')->sole();
        $this->assertSame($original->id, $successful->id);
        $this->assertSame($original->refund_no, $successful->refund_no);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->assertSame('SUCCEEDED', $service->refund($this->adminId, $this->appointmentId, 'failed'));
        $this->assertEquals($successful, DB::table('refunds')->sole());
        $this->saveEvidence($results, $state);
    }

    #[DataProvider('firstWriters')]
    public function test_payment_and_suspension_follow_the_protected_order(string $first): void
    {
        $this->prepareEvidence($first === 'suspend' ? 'suspension-first' : 'payment-first');
        $schedule = DB::table('schedules')->where('id', DB::table('slots')->find($this->slotId)->schedule_id)->sole();
        $pay = ['operation' => 'pay'];
        $suspend = ['operation' => 'suspend', 'user_id' => $this->adminId, 'schedule_id' => $schedule->id,
            'input' => ['doctor_id' => $schedule->doctor_id, 'work_date' => $schedule->work_date, 'session_code' => $schedule->session_code,
                'starts_at' => CarbonImmutable::parse($schedule->starts_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i'),
                'ends_at' => CarbonImmutable::parse($schedule->ends_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i'),
                'status' => 'SUSPENDED', 'suspend_reason' => '合成生命周期并发停诊']];
        $jobs = $first === 'suspend' ? [$suspend, $pay] : [$pay, $suspend];
        $jobs[0]['pause_after_lock'] = true;
        $this->startWorkers($jobs, 'departments');
        $this->awaitPhase('ready', [0, 1]);
        $this->signal(0, "GO\n");
        $this->awaitPhase('holding', [0]);
        $this->signal(1, "GO\n", true);
        $this->awaitPhase('locking', [1]);
        $this->signal(0, "RELEASE\n", true);
        $results = $this->finishWorkers();
        $paid = $first === 'pay';
        $this->assertSame($paid ? 'CONFIRMED' : 'SUSPENDED', $results[0]['result']);
        if ($paid) {
            $this->assertSame('SUSPENDED', $results[1]['result']);
        } else {
            $this->assertSame('rejected', $results[1]['outcome']);
            $this->assertSame(['appointment'], $results[1]['fields']);
        }
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'SUSPENDED']);
        $this->assertAudits(['APPOINTMENT_CREATED' => 1, 'SCHEDULE_SAVED' => 1, 'PAYMENT_CONFIRMED' => $paid ? 1 : 0]);
        $state = $this->assertLedger($paid ? 'CONFIRMED' : 'PENDING_PAYMENT', $paid ? 'PAID' : 'UNPAID');
        $this->saveEvidence($results, $state);
    }

    public static function firstWriters(): array
    {
        return ['suspension first' => ['suspend'], 'payment first' => ['pay']];
    }

    private function raceTogether(array $jobs): array
    {
        DB::beginTransaction();
        DB::table('patients')->where('id', $this->patientId)->lockForUpdate()->first();
        try {
            $this->startWorkers($jobs, 'patients');
            $this->awaitPhase('ready', [0, 1]);
            foreach ([0, 1] as $index) {
                $this->signal($index, "GO\n", true);
            }
            $this->awaitPhase('locking', [0, 1]);
        } finally {
            DB::rollBack();
        }

        return $this->finishWorkers();
    }

    private function startWorkers(array $jobs, string $gateTable): void
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => 'mediqueue_test',
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        foreach ($jobs as $job) {
            $job += ['appointment_id' => $this->appointmentId, 'user_id' => $this->userId,
                'test_now' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'), 'gate_table' => $gateTable];
            $input = new InputStream;
            $worker = new Process([PHP_BINARY, base_path('tests/Support/lifecycle-worker.php'), json_encode($job, JSON_THROW_ON_ERROR)], base_path(), $environment, $input, 60);
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

    private function awaitPhase(string $phase, array $indices): void
    {
        $deadline = microtime(true) + 40;
        do {
            $ready = 0;
            foreach ($indices as $index) {
                $worker = $this->workers[$index];
                if (array_filter($this->events($worker), fn ($event) => $event['phase'] === $phase)) {
                    $ready++;
                } elseif (! $worker->isRunning()) {
                    $this->fail('Worker exited before '.$phase.': '.$worker->getErrorOutput().' '.$worker->getOutput());
                }
            }
            if ($ready === count($indices)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out waiting for lifecycle workers at '.$phase.'.');
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
            $results[] = $events[0];
        }
        $this->assertCount(2, array_unique(array_column($results, 'pid')));
        $this->assertCount(2, array_unique(array_column($results, 'connection_id')));
        $this->assertNotContains((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, array_column($results, 'connection_id'));

        return $results;
    }

    private function assertAudits(array $expected): void
    {
        foreach ($expected as $action => $count) {
            $this->assertSame($count, DB::table('audit_logs')->where('action', $action)->count(), $action);
        }
        $this->assertSame(array_sum($expected), DB::table('audit_logs')->count());
    }

    private function assertLedger(string $appointmentStatus, string $orderStatus, ?string $refundStatus = null): array
    {
        $this->assertSame(1, DB::table('appointments')->count());
        $this->assertSame(1, DB::table('payment_orders')->count());
        $appointment = DB::table('appointments')->sole();
        $order = DB::table('payment_orders')->sole();
        $this->assertSame($this->appointmentId, $appointment->id);
        $this->assertSame($appointmentStatus, $appointment->status);
        $this->assertSame($appointment->id, $order->appointment_id);
        $this->assertSame($orderStatus, $order->status);
        $this->assertSame($appointment->fee_amount, $order->amount);
        $this->assertSame('MOCK_WECHAT', $order->channel);
        $this->assertNull($order->external_txn_id);
        $this->assertNull($order->external_paid_at);
        $released = in_array($appointmentStatus, ['CANCELLED', 'EXPIRED'], true);
        $this->assertSame($released, $appointment->released_at !== null);
        $this->assertSame(in_array($orderStatus, ['PAID', 'REFUND_PENDING', 'REFUNDED'], true), $order->paid_at !== null);
        $this->assertSame($orderStatus === 'CLOSED', $order->closed_at !== null);
        $this->assertSame($refundStatus === null ? 0 : 1, DB::table('refunds')->count());
        $refund = DB::table('refunds')->first();
        if ($refund) {
            $this->assertSame($order->id, $refund->payment_order_id);
            $this->assertSame($refundStatus, $refund->status);
            $this->assertSame($order->amount, $refund->amount);
            $this->assertNull($refund->external_refund_id);
            $this->assertSame($refundStatus === 'SUCCEEDED', $refund->succeeded_at !== null);
        }
        $slots = DB::select("SELECT s.id, s.capacity, s.occupied_count, COALESCE(a.actual, 0) actual FROM slots s LEFT JOIN (SELECT slot_id, COUNT(*) actual FROM appointments WHERE status IN ('PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED') GROUP BY slot_id) a ON a.slot_id = s.id ORDER BY s.id");
        foreach ($slots as $slot) {
            $this->assertSame((int) $slot->actual, $slot->occupied_count);
            $this->assertGreaterThanOrEqual(0, $slot->occupied_count);
            $this->assertLessThanOrEqual($slot->capacity, $slot->occupied_count);
        }

        return ['appointment_status' => $appointment->status, 'order_status' => $order->status,
            'refund_status' => $refund?->status, 'refund_count' => $refund ? 1 : 0, 'amount' => $order->amount,
            'expires_at' => $appointment->expires_at, 'paid_at' => $order->paid_at, 'released_at' => $appointment->released_at,
            'audits' => DB::table('audit_logs')->select('action')->selectRaw('COUNT(*) count')->groupBy('action')->orderBy('action')->get()->all(), 'slots' => $slots];
    }

    private function prepareEvidence(string $scenario): void
    {
        $this->scenario = $scenario;
        $path = base_path('test-results/lifecycle-concurrency-'.$scenario.'.json');
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
            'clock_mode' => 'Explicit controlled UTC time in each testing process; no production deadline change.',
            'participants' => count($results), 'workers' => $results, 'final_state' => $state];
        $this->assertNotFalse(file_put_contents($directory.'/lifecycle-concurrency-'.$this->scenario.'.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"));
    }
}
