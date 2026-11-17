<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HospitalResourcesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack();
            CarbonImmutable::setTestNow();
        });
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:30:00Z'));
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('username', 'demo_admin')->firstOrFail());
    }

    public function test_doctor_creation_is_atomic_and_does_not_accept_privileged_fields(): void
    {
        $department = DB::table('departments')->first();
        $this->post('/admin/doctors', [
            'username' => 'demo_new_doctor', 'password' => 'random demonstration password', 'password_confirmation' => 'random demonstration password',
            'department_id' => $department->id, 'full_name' => '演示医生丙', 'title' => '演示医师', 'status' => 'ACTIVE',
            'role' => 'ADMIN', 'user_id' => 999,
        ])->assertRedirect(route('admin.resources'));
        $user = DB::table('users')->where('username', 'demo_new_doctor')->sole();
        $this->assertSame('DOCTOR', $user->role);
        $this->assertTrue(Hash::check('random demonstration password', $user->password_hash));
        $this->assertDatabaseHas('doctors', ['user_id' => $user->id, 'department_id' => $department->id]);
        $audit = DB::table('audit_logs')->where('action', 'DOCTOR_SAVED')->sole();
        $this->assertStringNotContainsString('password', $audit->after_state);
    }

    public function test_introduction_length_is_rejected_before_exceeding_the_text_column(): void
    {
        $this->post('/admin/departments', [
            'code' => 'LONG_DESCRIPTION', 'name' => '演示超长介绍', 'status' => 'ACTIVE',
            'introduction' => str_repeat('测', 16001),
        ])->assertSessionHasErrors('introduction');
        $this->assertDatabaseMissing('departments', ['code' => 'LONG_DESCRIPTION']);
    }

    public function test_schedule_overlap_is_rejected_and_suspension_does_not_modify_slots(): void
    {
        $schedule = DB::table('schedules')->first();
        $input = ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-18', 'session_code' => 'AFTERNOON',
            'starts_at' => '2026-11-18T09:30', 'ends_at' => '2026-11-18T10:30', 'status' => 'OPEN'];
        $this->post('/admin/schedules', $input)->assertSessionHasErrors('starts_at');
        $slots = DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('id')->get()->toArray();
        $input = array_merge($input, ['session_code' => 'MORNING', 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T10:00', 'status' => 'SUSPENDED', 'suspend_reason' => '演示停诊']);
        $this->put('/admin/schedules/'.$schedule->id, $input)->assertRedirect(route('admin.resources'));
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'SUSPENDED']);
        $this->assertEquals($slots, DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'schedules', 'target_id' => $schedule->id, 'action' => 'SCHEDULE_SAVED']);
    }

    public function test_slot_edit_checks_capacity_and_does_not_accept_occupancy_or_parent_changes(): void
    {
        $slot = DB::table('slots')->orderBy('id')->first();
        DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 2]);
        $input = ['schedule_id' => $slot->schedule_id, 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T09:30',
            'capacity' => 1, 'fee_amount' => '22.00', 'status' => 'OPEN', 'occupied_count' => 0];
        $this->put('/admin/slots/'.$slot->id, $input)->assertSessionHasErrors('capacity');
        $input['capacity'] = 2;
        $this->put('/admin/slots/'.$slot->id, $input)->assertRedirect(route('admin.resources'));
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'occupied_count' => 2, 'capacity' => 2, 'fee_amount' => '22.00']);
    }

    public function test_schedule_rejects_cross_date_and_slot_overlap(): void
    {
        $schedule = DB::table('schedules')->first();
        $this->post('/admin/schedules', ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-19', 'session_code' => 'EVENING',
            'starts_at' => '2026-11-19T23:30', 'ends_at' => '2026-11-20T00:30', 'status' => 'OPEN'])->assertSessionHasErrors('starts_at');
        $this->post('/admin/slots', ['schedule_id' => $schedule->id, 'starts_at' => '2026-11-18T09:15', 'ends_at' => '2026-11-18T09:45',
            'capacity' => 2, 'fee_amount' => '12.34', 'status' => 'OPEN'])->assertSessionHasErrors('starts_at');
    }

    public function test_referenced_slot_identity_is_frozen_but_fee_can_change(): void
    {
        $slot = DB::table('slots')->orderBy('id')->first();
        $patient = DB::table('patients')->first();
        $created = '2026-11-16 20:30:00.000';
        DB::table('appointments')->insert([
            'appointment_no' => 'DEMO-HISTORY', 'patient_id' => $patient->id, 'slot_id' => $slot->id,
            'request_key' => 'demo-history', 'status' => 'PENDING_PAYMENT', 'fee_amount' => '12.34',
            'expires_at' => '2026-11-16 20:45:00.000', 'created_at' => $created, 'updated_at' => $created,
        ]);
        $input = ['schedule_id' => $slot->schedule_id, 'starts_at' => '2026-11-18T09:05', 'ends_at' => '2026-11-18T09:30',
            'capacity' => 3, 'fee_amount' => '40.00', 'status' => 'OPEN'];
        $this->put('/admin/slots/'.$slot->id, $input)->assertSessionHasErrors('starts_at');
        $input['starts_at'] = '2026-11-18T09:00';
        $this->put('/admin/slots/'.$slot->id, $input)->assertRedirect(route('admin.resources'));
        $this->assertDatabaseHas('appointments', ['appointment_no' => 'DEMO-HISTORY', 'fee_amount' => '12.34']);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'fee_amount' => '40.00']);
    }

    public function test_draft_can_be_configured_before_publication_and_inactive_account_blocks_opening(): void
    {
        $schedule = DB::table('schedules')->first();
        DB::table('slots')->where('schedule_id', $schedule->id)->delete();
        DB::table('schedules')->where('id', $schedule->id)->update(['status' => 'DRAFT']);
        $slot = ['schedule_id' => $schedule->id, 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T09:30', 'capacity' => 2, 'fee_amount' => '12.34', 'status' => 'OPEN'];
        $this->post('/admin/slots', $slot)->assertRedirect(route('admin.resources'));
        $doctor = DB::table('doctors')->find($schedule->doctor_id);
        DB::table('users')->where('id', $doctor->user_id)->update(['status' => 'DISABLED']);
        $this->post('/admin/slots', array_merge($slot, ['starts_at' => '2026-11-18T09:30', 'ends_at' => '2026-11-18T10:00']))->assertSessionHasErrors('schedule_id');
        $this->put('/admin/schedules/'.$schedule->id, ['doctor_id' => $doctor->id, 'work_date' => '2026-11-18', 'session_code' => 'MORNING', 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T10:00', 'status' => 'OPEN'])->assertSessionHasErrors('doctor_id');
    }

    public function test_started_resources_cannot_be_retimed_or_have_capacity_changed(): void
    {
        $schedule = DB::table('schedules')->first();
        $slot = DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('starts_at')->first();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18T01:05:00Z'));
        $this->put('/admin/slots/'.$slot->id, ['schedule_id' => $schedule->id, 'starts_at' => '2026-11-18T09:05', 'ends_at' => '2026-11-18T09:30', 'capacity' => 4, 'fee_amount' => '12.34', 'status' => 'OPEN'])->assertSessionHasErrors('starts_at');
        $this->put('/admin/slots/'.$slot->id, ['schedule_id' => $schedule->id, 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T09:30', 'capacity' => 4, 'fee_amount' => '12.34', 'status' => 'OPEN'])->assertSessionHasErrors('capacity');
        $this->put('/admin/schedules/'.$schedule->id, ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-18', 'session_code' => 'MORNING', 'starts_at' => '2026-11-18T09:01', 'ends_at' => '2026-11-18T10:00', 'status' => 'OPEN'])->assertSessionHasErrors('starts_at');
        $this->post('/admin/slots', ['schedule_id' => $schedule->id, 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T09:15', 'capacity' => 1, 'fee_amount' => '12.34', 'status' => 'OPEN'])->assertSessionHasErrors('starts_at');
    }

    public function test_audit_failure_rolls_back_resource_creation(): void
    {
        $injected = false;
        DB::listen(function (QueryExecuted $event) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower($event->sql), 'insert into `audit_logs`')) {
                $injected = true;
                throw new \RuntimeException('Injected audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/departments', ['code' => 'DEMO_FAILURE', 'name' => '演示回滚科室', 'status' => 'ACTIVE']);
            $this->fail('Expected the audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected audit failure', $e->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertDatabaseMissing('departments', ['code' => 'DEMO_FAILURE']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'DEPARTMENT_SAVED']);
    }

    public function test_cannot_create_past_schedule_or_reopen_ended_schedule(): void
    {
        $schedule = DB::table('schedules')->first();
        $this->post('/admin/schedules', ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-16', 'session_code' => 'AFTERNOON', 'starts_at' => '2026-11-16T09:00', 'ends_at' => '2026-11-16T10:00', 'status' => 'DRAFT'])->assertSessionHasErrors('starts_at');
        DB::table('schedules')->where('id', $schedule->id)->update(['status' => 'CLOSED']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18T02:00:00Z'));
        $this->put('/admin/schedules/'.$schedule->id, ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-18', 'session_code' => 'MORNING', 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T10:00', 'status' => 'OPEN'])->assertSessionHasErrors('ends_at');
    }

    public function test_doctor_and_account_roll_back_when_audit_fails(): void
    {
        $department = DB::table('departments')->first();
        $injected = false;
        DB::listen(function (QueryExecuted $event) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower($event->sql), 'insert into `audit_logs`')) {
                $injected = true;
                throw new \RuntimeException('Audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/doctors', ['username' => 'demo_rollback', 'password' => 'random demonstration password', 'password_confirmation' => 'random demonstration password', 'department_id' => $department->id, 'full_name' => '演示医生回滚', 'title' => '演示医师', 'status' => 'ACTIVE']);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failure', $e->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertDatabaseMissing('users', ['username' => 'demo_rollback']);
        $this->assertDatabaseMissing('doctors', ['full_name' => '演示医生回滚']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'DOCTOR_SAVED']);
    }

    public function test_suspension_and_restore_preserve_existing_appointment_and_payment(): void
    {
        $schedule = DB::table('schedules')->first();
        $slot = DB::table('slots')->where('schedule_id', $schedule->id)->first();
        DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 1]);
        $now = '2026-11-16 20:30:00.000';
        $appointment = DB::table('appointments')->insertGetId(['appointment_no' => 'DEMO-PENDING', 'patient_id' => DB::table('patients')->first()->id, 'slot_id' => $slot->id, 'request_key' => 'demo-pending', 'status' => 'PENDING_PAYMENT', 'fee_amount' => '12.34', 'expires_at' => '2026-11-16 20:45:00.000', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('payment_orders')->insert(['appointment_id' => $appointment, 'order_no' => 'DEMO-ORDER', 'amount' => '12.34', 'currency' => 'CNY', 'channel' => 'MOCK_WECHAT', 'status' => 'UNPAID', 'created_at' => $now, 'updated_at' => $now]);
        $before = [DB::table('appointments')->find($appointment), DB::table('payment_orders')->where('appointment_id', $appointment)->first(), DB::table('slots')->find($slot->id)];
        $input = ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-18', 'session_code' => 'MORNING', 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T10:00', 'status' => 'SUSPENDED', 'suspend_reason' => '演示停诊'];
        $this->put('/admin/schedules/'.$schedule->id, $input)->assertRedirect();
        $input['status'] = 'OPEN';
        $this->put('/admin/schedules/'.$schedule->id, $input)->assertRedirect();
        $this->assertEquals($before, [DB::table('appointments')->find($appointment), DB::table('payment_orders')->where('appointment_id', $appointment)->first(), DB::table('slots')->find($slot->id)]);
        $input['ends_at'] = '2026-11-18T10:30';
        $this->put('/admin/schedules/'.$schedule->id, $input)->assertSessionHasErrors('starts_at');
    }

    public function test_invalid_resource_values_and_adjacent_intervals(): void
    {
        $schedule = DB::table('schedules')->first();
        $base = ['schedule_id' => $schedule->id, 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T09:30', 'capacity' => 2, 'fee_amount' => '12.34', 'status' => 'OPEN'];
        foreach ([['starts_at' => '2026-11-18T08:30'], ['capacity' => 0], ['capacity' => -1], ['fee_amount' => '0.00'], ['fee_amount' => '1.234'], ['status' => 'INVALID']] as $invalid) {
            $this->post('/admin/slots', array_merge($base, $invalid))->assertSessionHasErrors(array_key_first($invalid));
        }
        $input = ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-18', 'session_code' => 'AFTERNOON', 'starts_at' => '2026-11-18T10:00', 'ends_at' => '2026-11-18T11:00', 'status' => 'SUSPENDED'];
        $this->post('/admin/schedules', $input)->assertSessionHasErrors('suspend_reason');
        $input['status'] = 'OPEN';
        $this->post('/admin/schedules', $input)->assertRedirect(route('admin.resources'));
        $new = DB::table('schedules')->where('doctor_id', $schedule->doctor_id)->where('session_code', 'AFTERNOON')->sole();
        $this->post('/admin/slots', array_merge($base, ['schedule_id' => $new->id, 'starts_at' => '2026-11-18T10:00', 'ends_at' => '2026-11-18T10:30']))->assertRedirect();
        $this->post('/admin/slots', array_merge($base, ['schedule_id' => $new->id, 'starts_at' => '2026-11-18T10:30', 'ends_at' => '2026-11-18T11:00']))->assertRedirect();
        $this->put('/admin/schedules/'.$schedule->id, ['doctor_id' => $schedule->doctor_id, 'work_date' => '2026-11-18', 'session_code' => 'MORNING', 'starts_at' => '2026-11-18T09:00', 'ends_at' => '2026-11-18T09:30', 'status' => 'OPEN'])->assertSessionHasErrors('starts_at');
        foreach (['departments', 'doctors', 'schedules', 'slots'] as $resource) {
            $this->delete('/admin/'.$resource.'/1')->assertStatus(405);
        }
    }
}
