<?php

namespace Tests\Feature;

use App\Services\AppointmentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use Tests\TestCase;

class AppointmentRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Committed fixtures allow a second real connection and post-COMMIT recovery.
        $this->seed();
        $this->beforeApplicationDestroyed(function (): void {
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

    public function test_real_lock_timeout_retries_three_complete_transactions_then_leaves_no_writes(): void
    {
        $slot = DB::table('slots')->orderBy('id')->first();
        $userId = DB::table('patients')->orderBy('id')->value('user_id');
        config(['database.connections.blocker' => config('database.connections.mysql')]);
        $blocker = DB::connection('blocker');
        $this->assertNotSame(DB::selectOne('SELECT CONNECTION_ID() id')->id, $blocker->selectOne('SELECT CONNECTION_ID() id')->id);
        $blocker->beginTransaction();
        $blocker->table('slots')->where('id', $slot->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $attempts = 0;
        $this->app['events']->listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$attempts): void {
            if ($event->connectionName === 'mysql') {
                $attempts++;
            }
        });
        try {
            app(AppointmentService::class)->create($userId, $slot->id, 'lock-timeout-original');
            $this->fail('Expected bounded lock-timeout rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
            $this->assertStringContainsString('原请求标识', $exception->errors()['request_key'][0]);
        } finally {
            $blocker->rollBack();
            DB::purge('blocker');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $this->assertSame(3, $attempts);
        $this->assertSame(0, DB::transactionLevel());
        foreach (['appointments', 'payment_orders', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame(0, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
        app(AppointmentService::class)->create($userId, $slot->id, 'lock-timeout-original');
        $this->assertOneConsistentCreation($slot->id);
    }

    public function test_injected_deadlock_after_order_insert_retries_the_whole_transaction(): void
    {
        $slotId = DB::table('slots')->orderBy('id')->value('id');
        $userId = DB::table('patients')->orderBy('id')->value('user_id');
        $inserts = 0;
        DB::listen(function (QueryExecuted $event) use (&$inserts): void {
            if (str_starts_with($event->sql, 'insert into `payment_orders`') && ++$inserts === 1) {
                throw new PDOException('Deadlock found when trying to get lock', 40001);
            }
        });
        app(AppointmentService::class)->create($userId, $slotId, 'retry-original-key');
        $this->assertSame(2, $inserts);
        $this->assertOneConsistentCreation($slotId);
    }

    public function test_unknown_commit_result_reconnects_and_recovers_by_original_key_without_another_booking(): void
    {
        $slotId = DB::table('slots')->orderBy('id')->value('id');
        $userId = DB::table('patients')->orderBy('id')->value('user_id');
        $connectionId = DB::selectOne('SELECT CONNECTION_ID() id')->id;
        $injected = false;
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$injected): void {
            if (! $injected) {
                $injected = true;
                // The actual MySQL COMMIT has completed; simulate losing its response.
                throw new PDOException('MySQL server has gone away', 2006);
            }
        });
        $id = app(AppointmentService::class)->create($userId, $slotId, 'unknown-commit-key');
        $this->assertTrue($injected);
        $this->assertNotSame($connectionId, DB::selectOne('SELECT CONNECTION_ID() id')->id);
        $this->assertSame($id, app(AppointmentService::class)->create($userId, $slotId, 'unknown-commit-key'));
        $this->assertOneConsistentCreation($slotId);
    }

    public function test_disconnection_before_commit_does_not_blindly_resubmit_and_original_key_can_be_retried(): void
    {
        $slotId = DB::table('slots')->orderBy('id')->value('id');
        $userId = DB::table('patients')->orderBy('id')->value('user_id');
        $injected = false;
        DB::listen(function (QueryExecuted $event) use (&$injected): void {
            if (! $injected && str_starts_with($event->sql, 'insert into `payment_orders`')) {
                $injected = true;
                throw new PDOException('MySQL server has gone away', 2006);
            }
        });
        try {
            app(AppointmentService::class)->create($userId, $slotId, 'unknown-empty-key');
            $this->fail('An unknown result must not silently create a new attempt.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('尚未确认', $exception->errors()['request_key'][0]);
        }
        $this->assertTrue($injected);
        foreach (['appointments', 'payment_orders', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame(0, DB::table('slots')->where('id', $slotId)->value('occupied_count'));
        app(AppointmentService::class)->create($userId, $slotId, 'unknown-empty-key');
        $this->assertOneConsistentCreation($slotId);
    }

    private function assertOneConsistentCreation(int $slotId): void
    {
        foreach (['appointments', 'payment_orders', 'audit_logs'] as $table) {
            $this->assertSame(1, DB::table($table)->count());
        }
        $appointment = DB::table('appointments')->first();
        $order = DB::table('payment_orders')->first();
        $this->assertSame($appointment->id, $order->appointment_id);
        $this->assertSame($appointment->fee_amount, $order->amount);
        $this->assertSame(1, DB::table('slots')->where('id', $slotId)->value('occupied_count'));
        $this->assertSame(0, DB::transactionLevel());
    }
}
