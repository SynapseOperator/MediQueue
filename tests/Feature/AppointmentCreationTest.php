<?php

namespace Tests\Feature;

use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AppointmentCreationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack();
            CarbonImmutable::setTestNow();
        });
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:30:00.123Z'));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_creation_snapshots_fee_and_is_idempotent_for_exact_key_and_input(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $service = app(AppointmentService::class);
        $id = $service->create($user, $slot->id, 'Request-A');
        $this->assertSame($id, $service->create($user, $slot->id, 'Request-A'));
        $appointment = DB::table('appointments')->find($id);
        $this->assertSame('PENDING_PAYMENT', $appointment->status);
        $this->assertSame($slot->fee_amount, $appointment->fee_amount);
        $this->assertSame('2026-11-16 20:45:00.123', $appointment->expires_at);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $id, 'amount' => $slot->fee_amount, 'status' => 'UNPAID', 'channel' => 'MOCK_WECHAT']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'APPOINTMENT_CREATED', 'target_type' => 'appointments', 'target_id' => $id]);
        $this->assertSame(1, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
        DB::table('slots')->where('id', $slot->id)->update(['fee_amount' => '99.99']);
        $this->assertSame($slot->fee_amount, DB::table('appointments')->where('id', $id)->value('fee_amount'));
        $this->assertSame($slot->fee_amount, DB::table('payment_orders')->where('appointment_id', $id)->value('amount'));
        $other = DB::table('slots')->where('id', '<>', $slot->id)->value('id');
        try {
            $service->create($user, $other, 'Request-A');
            $this->fail('Expected idempotency conflict.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request_key', $e->errors());
        }
        $this->assertSame(1, DB::table('appointments')->count());
        $this->assertSame(1, DB::table('payment_orders')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'APPOINTMENT_CREATED')->count());
    }

    public function test_overlap_is_rejected_but_adjacent_slot_and_case_distinct_keys_are_allowed(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slots = DB::table('slots')->orderBy('id')->get();
        $service = app(AppointmentService::class);
        $service->create($user, $slots[0]->id, 'Key');
        try {
            $service->create($user, $slots[2]->id, 'other');
            $this->fail('Expected overlapping doctors to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slot_id', $e->errors());
        }
        $service->create($user, $slots[1]->id, 'key');
        $this->assertSame(2, DB::table('appointments')->count());
    }

    public function test_closed_suspended_started_and_full_slots_are_rejected(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $service = app(AppointmentService::class);
        foreach (['suspended', 'closed', 'full', 'started'] as $case) {
            if ($case === 'suspended') {
                DB::table('schedules')->where('id', $slot->schedule_id)->update(['status' => 'SUSPENDED', 'suspend_reason' => '演示']);
            }
            if ($case === 'closed') {
                DB::table('schedules')->where('id', $slot->schedule_id)->update(['status' => 'OPEN', 'suspend_reason' => null]);
                DB::table('slots')->where('id', $slot->id)->update(['status' => 'CLOSED']);
            }
            if ($case === 'full') {
                DB::table('slots')->where('id', $slot->id)->update(['status' => 'OPEN', 'occupied_count' => $slot->capacity]);
            }
            if ($case === 'started') {
                DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 0]);
                CarbonImmutable::setTestNow(CarbonImmutable::parse($slot->starts_at, 'UTC'));
            }
            try {
                $service->create($user, $slot->id, 'reject-'.$case);
                $this->fail('Expected rejection: '.$case);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('slot_id', $e->errors());
            }
        }
        $this->assertSame(0, DB::table('appointments')->count());
    }

    public function test_failure_after_each_insert_rolls_back_appointment_order_audit_and_capacity(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $target = '';
        DB::listen(function (QueryExecuted $event) use (&$target): void {
            if ($target !== '' && str_starts_with(strtolower($event->sql), 'insert into `'.$target.'`')) {
                $target = '';
                throw new RuntimeException('Injected failure');
            }
        });
        foreach (['appointments', 'payment_orders', 'audit_logs'] as $table) {
            $target = $table;
            try {
                app(AppointmentService::class)->create($user, $slot->id, 'failure-'.$table);
                $this->fail('Expected failure after '.$table);
            } catch (RuntimeException $e) {
                $this->assertSame('Injected failure', $e->getMessage());
            }
            $this->assertSame('', $target);
            $this->assertSame(0, DB::table('appointments')->count());
            $this->assertSame(0, DB::table('payment_orders')->count());
            $this->assertSame(0, DB::table('audit_logs')->count());
            $this->assertSame(0, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
        }
    }

    public function test_cancelled_and_expired_history_does_not_block_new_booking(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $patient = DB::table('patients')->where('user_id', $user)->value('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $admin = DB::table('users')->where('username', 'demo_admin')->value('id');
        foreach (['CANCELLED', 'EXPIRED'] as $status) {
            $row = ['appointment_no' => 'HISTORY-'.$status, 'patient_id' => $patient, 'slot_id' => $slot->id, 'request_key' => 'history-'.$status, 'status' => $status, 'fee_amount' => $slot->fee_amount, 'created_at' => '2026-11-16 20:00:00.000', 'updated_at' => '2026-11-16 20:15:00.000', 'expires_at' => '2026-11-16 20:15:00.000', 'released_at' => '2026-11-16 20:15:00.000'];
            if ($status === 'CANCELLED') {
                $row += ['cancelled_at' => '2026-11-16 20:15:00.000', 'cancelled_by' => $admin, 'cancel_reason_code' => 'PATIENT_REQUEST'];
            }
            $historyId = DB::table('appointments')->insertGetId($row);
            DB::table('payment_orders')->insert(['appointment_id' => $historyId, 'order_no' => 'HISTORY-ORDER-'.$status, 'amount' => $slot->fee_amount, 'currency' => 'CNY', 'channel' => 'MOCK_WECHAT', 'status' => 'CLOSED', 'closed_at' => '2026-11-16 20:15:00.000', 'created_at' => '2026-11-16 20:00:00.000', 'updated_at' => '2026-11-16 20:15:00.000']);
            if ($status === 'CANCELLED') {
                $cancelledId = $historyId;
            }
        }
        $this->assertSame($cancelledId, app(AppointmentService::class)->create($user, $slot->id, 'history-CANCELLED'));
        $id = app(AppointmentService::class)->create($user, $slot->id, 'new-booking');
        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'PENDING_PAYMENT']);
    }

    public function test_nonpatient_account_is_forbidden(): void
    {
        $user = DB::table('users')->where('username', 'demo_admin')->value('id');
        $slot = DB::table('slots')->orderBy('id')->value('id');
        try {
            app(AppointmentService::class)->create($user, $slot, 'not-patient');
            $this->fail('Expected forbidden account.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('appointments')->count());
    }

    public function test_deadline_is_capped_by_appointment_start(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        CarbonImmutable::setTestNow(CarbonImmutable::parse($slot->starts_at, 'UTC')->subMinutes(5));
        $id = app(AppointmentService::class)->create($user, $slot->id, 'near-start');
        $this->assertSame($slot->starts_at, DB::table('appointments')->where('id', $id)->value('expires_at'));
    }

    public function test_inactive_parent_resources_and_missing_patient_are_rejected(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $schedule = DB::table('schedules')->find($slot->schedule_id);
        $doctor = DB::table('doctors')->find($schedule->doctor_id);
        foreach ([['departments', $schedule->department_id], ['doctors', $doctor->id], ['users', $doctor->user_id]] as [$table, $id]) {
            DB::table($table)->where('id', $id)->update(['status' => 'DISABLED']);
            try {
                app(AppointmentService::class)->create($user, $slot->id, 'disabled-'.$table);
                $this->fail('Expected inactive resource rejection.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('slot_id', $e->errors());
            }
            DB::table($table)->where('id', $id)->update(['status' => 'ACTIVE']);
        }
        DB::table('patients')->where('user_id', $user)->delete();
        try {
            app(AppointmentService::class)->create($user, $slot->id, 'missing-profile');
            $this->fail('Expected forbidden missing profile.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_expired_unreclaimed_pending_still_consumes_capacity(): void
    {
        $users = DB::table('users')->whereIn('username', ['demo_patient_1', 'demo_patient_2'])->orderBy('username')->pluck('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        DB::table('slots')->where('id', $slot->id)->update(['capacity' => 1]);
        app(AppointmentService::class)->create($users[0], $slot->id, 'expires');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:46:00Z'));
        try {
            app(AppointmentService::class)->create($users[1], $slot->id, 'still-full');
            $this->fail('Expected unreclaimed capacity to remain occupied.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slot_id', $e->errors());
        }
        $this->assertSame(1, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
    }

    public function test_confirmed_and_completed_appointments_block_overlaps(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slots = DB::table('slots')->orderBy('id')->get();
        $id = app(AppointmentService::class)->create($user, $slots[0]->id, 'existing-active');
        $admin = DB::table('users')->where('username', 'demo_admin')->value('id');
        foreach (['CONFIRMED', 'COMPLETED'] as $status) {
            $values = ['status' => $status, 'confirmed_at' => '2026-11-16 20:31:00.000'];
            if ($status === 'COMPLETED') {
                $values += ['completed_at' => '2026-11-16 20:32:00.000', 'completed_by' => $admin];
            }
            DB::table('appointments')->where('id', $id)->update($values);
            DB::table('payment_orders')->where('appointment_id', $id)->update(['status' => 'PAID', 'paid_at' => '2026-11-16 20:31:00.000']);
            try {
                app(AppointmentService::class)->create($user, $slots[2]->id, 'overlap-'.$status);
                $this->fail('Expected active history to block overlap.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('slot_id', $e->errors());
            }
        }
    }

    public function test_time_is_taken_after_slot_lock(): void
    {
        $user = DB::table('users')->where('username', 'demo_patient_1')->value('id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $advanced = false;
        DB::listen(function (QueryExecuted $event) use (&$advanced, $slot): void {
            if (! $advanced && str_contains(strtolower($event->sql), 'from `slots`') && str_contains(strtolower($event->sql), 'for update')) {
                $advanced = true;
                CarbonImmutable::setTestNow(CarbonImmutable::parse($slot->starts_at, 'UTC'));
            }
        });
        try {
            app(AppointmentService::class)->create($user, $slot->id, 'lock-time');
            $this->fail('Expected started slot rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slot_id', $e->errors());
        }
        $this->assertTrue($advanced);
        $this->assertSame(0, DB::table('appointments')->count());
    }
}
