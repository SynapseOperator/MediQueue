<?php

namespace Tests\Feature;

use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use Tests\TestCase;

class LifecycleRecoveryTest extends TestCase
{
    public function test_lost_commit_response_preserves_payment_cancellation_and_original_refund(): void
    {
        // Real commits, not an enclosing test transaction: the injected response loss happens after MySQL COMMIT.
        $this->seed();
        $this->beforeApplicationDestroyed(function (): void {
            DB::transaction(function (): void {
                foreach (['audit_logs', 'refunds', 'payment_orders', 'appointments', 'slots', 'schedules', 'doctors', 'departments', 'patients', 'users'] as $table) {
                    DB::table($table)->delete();
                }
            });
        });
        config(['mediqueue.mock_payments_enabled' => true]);
        $patient = DB::table('patients')->orderBy('id')->value('user_id');
        $admin = DB::table('users')->where('role', 'ADMIN')->value('id');
        $slot = DB::table('slots')->orderBy('id')->value('id');
        $id = app(AppointmentService::class)->create($patient, $slot, 'lifecycle-lost-response');
        $service = app(AppointmentLifecycleService::class);
        $armed = false;
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                throw new PDOException('MySQL server has gone away', 2006);
            }
        });
        foreach ([['pay', $patient, 'CONFIRMED'], ['cancel', $patient, 'CANCELLED'], ['refund', $admin, 'SUCCEEDED']] as [$method, $actor, $result]) {
            $connection = DB::selectOne('SELECT CONNECTION_ID() id')->id;
            $armed = true;
            try {
                $service->$method($actor, $id);
                $this->fail('The caller must be told the commit result is unknown.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('尚未确认', $e->errors()['appointment'][0]);
            }
            $this->assertFalse($armed);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNotSame($connection, DB::selectOne('SELECT CONNECTION_ID() id')->id);
            // Refresh the same business records, then retry exactly the original operation.
            $snapshot = [];
            foreach (['appointments', 'payment_orders', 'refunds', 'audit_logs', 'slots'] as $table) {
                $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
            $this->assertSame($result, $service->$method($actor, $id));
            foreach ($snapshot as $table => $expected) {
                $this->assertSame($expected, DB::table($table)->orderBy('id')->get()->toJson(), $table);
            }
        }
        $this->assertSame(1, DB::table('appointments')->count());
        $this->assertSame(1, DB::table('payment_orders')->count());
        $this->assertSame(1, DB::table('refunds')->count());
        $this->assertSame(4, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $id, 'status' => 'REFUNDED']);
        $this->assertSame(0, DB::table('slots')->where('id', $slot)->value('occupied_count'));
    }
}
