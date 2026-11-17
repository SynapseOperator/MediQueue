<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppointmentHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack();
            CarbonImmutable::setTestNow();
        });
        CarbonImmutable::setTestNow('2026-11-16T20:30:00Z');
        $this->seed();
        $this->withoutVite();
        $this->app->instance('env', 'local');
    }

    public function test_auth_role_and_csrf_are_required(): void
    {
        $payload = ['slot_id' => DB::table('slots')->value('id'), 'request_key' => 'request_key_123456'];
        $this->get('/appointments')->assertRedirect('/login');
        $this->withSession(['_token' => 'test-token'])->post('/appointments', $payload + ['_token' => 'test-token'])->assertRedirect('/login');
        $doctor = User::where('role', 'DOCTOR')->firstOrFail();
        $this->actingAs($doctor)->get('/appointments')->assertForbidden();
        $this->actingAs($doctor)->withSession(['_token' => 'test-token'])->post('/appointments', $payload + ['_token' => 'test-token'])->assertForbidden();
        $patient = User::where('role', 'PATIENT')->firstOrFail();
        $this->actingAs($patient)->post('/appointments', $payload)->assertStatus(419);
    }

    public function test_untrusted_fields_and_untrimmed_key_are_rejected(): void
    {
        $patient = User::where('role', 'PATIENT')->firstOrFail();
        $payload = ['slot_id' => DB::table('slots')->value('id'), 'request_key' => 'request_key_123456', '_token' => 'test-token'];
        foreach (['patient_id', 'user_id', 'role', 'status', 'amount', 'fee_amount', 'other'] as $field) {
            $this->actingAs($patient)->withSession(['_token' => 'test-token'])->post('/appointments', $payload + [$field => '1'])->assertSessionHasErrors();
        }
        foreach ([' request_key_123456', 'request_key_123456 ', 'too_short', 'request_key_中文123456'] as $key) {
            $this->actingAs($patient)->withSession(['_token' => 'test-token'])->post('/appointments', array_replace($payload, ['request_key' => $key]))->assertSessionHasErrors('request_key');
        }
        $this->assertDatabaseCount('appointments', 0);
        $doctorId = DB::table('schedules')->where('id', DB::table('slots')->where('id', $payload['slot_id'])->value('schedule_id'))->value('doctor_id');
        $doctorPage = route('doctors.show', $doctorId);
        $this->from($doctorPage)->withSession(['_token' => 'test-token'])
            ->post('/appointments', array_replace($payload, ['slot_id' => (string) $payload['slot_id'], 'request_key' => ['invalid']]))
            ->assertSessionHasErrors('request_key');
        $this->get($doctorPage)->assertOk();
        $this->from($doctorPage)->withSession(['_token' => 'test-token'])
            ->post('/appointments', array_replace($payload, ['slot_id' => (string) $payload['slot_id'], 'patient_id' => '1']))
            ->assertSessionHasErrors('slot_id');
        $this->get($doctorPage)->assertOk()->assertSee('value="request_key_123456"', false);
    }

    public function test_patient_can_create_replay_find_and_view_only_own_appointment(): void
    {
        $patients = User::where('role', 'PATIENT')->orderBy('id')->get();
        $payload = ['slot_id' => DB::table('slots')->value('id'), 'request_key' => 'request_key_123456', '_token' => 'test-token'];
        $response = $this->actingAs($patients[0])->withSession(['_token' => 'test-token'])->post('/appointments', $payload);
        $id = DB::table('appointments')->value('id');
        $response->assertRedirect(route('appointments.show', $id));
        $this->actingAs($patients[0])->withSession(['_token' => 'test-token'])->post('/appointments', $payload)->assertRedirect(route('appointments.show', $id));
        $this->assertDatabaseCount('appointments', 1);
        $this->get('/appointments')->assertOk()->assertSee(DB::table('appointments')->value('appointment_no'));
        $this->get('/appointments/'.$id)->assertOk()->assertSee('UNPAID / 待支付')->assertSee('服务端计算的截止剩余时间')->assertSee('模拟微信支付／无实际扣款')->assertDontSee('立即付款');
        $this->flushSession();
        $this->actingAs($patients[1])->get('/appointments/'.$id)->assertNotFound();
        $this->get('/appointments')->assertOk()->assertDontSee(DB::table('appointments')->value('appointment_no'));
    }

    public function test_expired_and_suspended_appointments_do_not_claim_to_be_payable(): void
    {
        $patient = User::where('role', 'PATIENT')->firstOrFail();
        $slot = DB::table('slots')->first();
        $payload = ['slot_id' => $slot->id, 'request_key' => 'request_key_123456', '_token' => 'test-token'];
        $this->actingAs($patient)->withSession(['_token' => 'test-token'])->post('/appointments', $payload);
        $id = DB::table('appointments')->value('id');
        DB::table('schedules')->where('id', $slot->schedule_id)->update(['status' => 'SUSPENDED', 'suspend_reason' => '演示停诊']);
        $this->get('/appointments/'.$id)->assertSee('付款资格已失效')->assertSee('状态尚未改变');
        CarbonImmutable::setTestNow('2026-11-16T20:46:00Z');
        $this->get('/appointments/'.$id)->assertSee('付款资格已到期')->assertSee('状态尚未改变');
        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'PENDING_PAYMENT']);
    }
}
