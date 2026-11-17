<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ResourceConcurrencyTest extends TestCase
{
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // These committed fixtures must be visible to independent worker connections.
        // The seeder first rejects any existing core data; TestCase guards the database name.
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

    public function test_concurrent_overlapping_schedules_commit_only_one_resource_and_audit(): void
    {
        $doctor = DB::table('doctors')->orderBy('id')->first();
        $date = CarbonImmutable::now('Asia/Shanghai')->addDays(2)->toDateString();
        $base = [
            'doctor_id' => $doctor->id, 'work_date' => $date,
            'status' => 'OPEN', 'suspend_reason' => null,
        ];
        $before = DB::table('schedules')->count();

        // Different session codes avoid the unique key masking a missing overlap lock.
        $this->race('schedule', $doctor->department_id, [
            $base + ['session_code' => 'MORNING', 'starts_at' => $date.'T09:00', 'ends_at' => $date.'T10:00'],
            $base + ['session_code' => 'AFTERNOON', 'starts_at' => $date.'T09:30', 'ends_at' => $date.'T10:30'],
        ]);

        $rows = DB::table('schedules')->where('doctor_id', $doctor->id)->where('work_date', $date)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($before + 1, DB::table('schedules')->count());
        $this->assertCommittedAudit('schedules', $rows->first(), 'SCHEDULE_SAVED');
    }

    public function test_concurrent_overlapping_slots_commit_only_one_resource_and_audit(): void
    {
        $doctor = DB::table('doctors')->orderBy('id')->first();
        $start = CarbonImmutable::now('Asia/Shanghai')->addDays(2)->startOfDay()->setTime(9, 0);
        $now = now('UTC')->format('Y-m-d H:i:s.v');
        $scheduleId = DB::table('schedules')->insertGetId([
            'doctor_id' => $doctor->id, 'department_id' => $doctor->department_id,
            'work_date' => $start->toDateString(), 'session_code' => 'MORNING',
            'starts_at' => $start->utc()->format('Y-m-d H:i:s.v'),
            'ends_at' => $start->addHours(2)->utc()->format('Y-m-d H:i:s.v'),
            'status' => 'OPEN', 'created_by' => DB::table('users')->where('role', 'ADMIN')->value('id'),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $date = $start->toDateString();
        $base = ['schedule_id' => $scheduleId, 'capacity' => 3, 'fee_amount' => '12.34', 'status' => 'OPEN'];
        $before = DB::table('slots')->count();

        // Distinct start times ensure rejection comes from interval overlap, not uniqueness.
        $this->race('slot', $doctor->department_id, [
            $base + ['starts_at' => $date.'T09:00', 'ends_at' => $date.'T09:30'],
            $base + ['starts_at' => $date.'T09:15', 'ends_at' => $date.'T09:45'],
        ]);

        $rows = DB::table('slots')->where('schedule_id', $scheduleId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($before + 1, DB::table('slots')->count());
        $this->assertSame(0, $rows->first()->occupied_count);
        $this->assertCommittedAudit('slots', $rows->first(), 'SLOT_SAVED');
    }

    private function race(string $operation, int $departmentId, array $payloads): void
    {
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => 'mediqueue_test', 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        ];
        $adminId = DB::table('users')->where('role', 'ADMIN')->value('id');
        $inputs = [];

        // Hold the first common lock, so both workers reach the same contested row
        // before either can finish its overlap check and commit.
        DB::beginTransaction();
        DB::table('departments')->where('id', $departmentId)->lockForUpdate()->first();
        try {
            foreach ($payloads as $payload) {
                $input = new InputStream;
                $worker = new Process([
                    PHP_BINARY, base_path('tests/Support/resource-worker.php'), $operation,
                    (string) $adminId, json_encode($payload, JSON_THROW_ON_ERROR),
                ], base_path(), $environment, $input, 30);
                $this->workers[] = $worker;
                $inputs[] = $input;
                $worker->start();
            }
            $ready = $this->awaitPhase('ready');
            $this->assertCount(2, array_unique(array_column($ready, 'pid')));
            $this->assertCount(2, array_unique(array_column($ready, 'connection_id')));
            $parentConnectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotContains($parentConnectionId, array_column($ready, 'connection_id'));
            foreach ($inputs as $input) {
                $input->write("GO\n");
                $input->close();
            }
            $this->awaitPhase('locking');
        } finally {
            DB::rollBack();
        }

        $outcomes = [];
        foreach ($this->workers as $worker) {
            $exit = $worker->wait();
            $this->assertSame(0, $exit, $worker->getErrorOutput());
            $events = $this->events($worker);
            $results = array_values(array_filter($events, fn ($event) => $event['phase'] === 'result'));
            $this->assertCount(1, $results, $worker->getOutput());
            $outcomes[] = $results[0]['outcome'];
            if ($results[0]['outcome'] === 'rejected') {
                $this->assertSame(['starts_at'], $results[0]['fields']);
            }
        }
        sort($outcomes);
        $this->assertSame(['created', 'rejected'], $outcomes);
    }

    private function awaitPhase(string $phase): array
    {
        $deadline = microtime(true) + 15;
        do {
            $matched = [];
            foreach ($this->workers as $worker) {
                $events = array_values(array_filter($this->events($worker), fn ($event) => $event['phase'] === $phase));
                if ($events !== []) {
                    $matched[] = $events[0];
                } elseif (! $worker->isRunning()) {
                    $this->fail('Worker exited before '.$phase.': '.$worker->getErrorOutput().' '.$worker->getOutput());
                }
            }
            if (count($matched) === count($this->workers)) {
                return $matched;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for both independent workers to reach '.$phase.'.');
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

    private function assertCommittedAudit(string $table, object $resource, string $action): void
    {
        $this->assertSame(1, DB::table('audit_logs')->count());
        $audit = DB::table('audit_logs')->first();
        $this->assertSame($action, $audit->action);
        $this->assertSame($table, $audit->target_type);
        $this->assertSame($resource->id, $audit->target_id);
        $this->assertSame('USER', $audit->actor_type);
        $this->assertSame(DB::table('users')->where('role', 'ADMIN')->value('id'), $audit->operator_id);
        $this->assertNull($audit->before_state);
        $after = json_decode($audit->after_state, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($resource->starts_at, $after['starts_at']);
        $this->assertSame($resource->ends_at, $after['ends_at']);
        $this->assertSame($resource->status, $after['status']);
        foreach (['appointments', 'payment_orders', 'refunds'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }
}
