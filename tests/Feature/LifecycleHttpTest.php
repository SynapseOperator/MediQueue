<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LifecycleHttpTest extends TestCase
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
        config(['mediqueue.mock_payments_enabled' => true]);
    }

    public function test_payment_requires_signed_url_csrf_role_ownership_and_enabled_mock(): void
    {
        $patients = User::where('role', 'PATIENT')->orderBy('id')->get();
        $doctor = User::where('role', 'DOCTOR')->firstOrFail();
        $id = app(AppointmentService::class)->create($patients[0]->id, DB::table('slots')->value('id'), 'http_payment_key_123');
        $url = URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $id]);
        $payload = ['_token' => 'test-token', 'scenario' => 'success'];
        $this->actingAs($patients[0])->withSession(['_token' => 'test-token'])->post('/appointments/'.$id.'/pay', $payload)->assertForbidden();
        $this->post($url, ['scenario' => 'success'])->assertStatus(419);
        $this->flushSession();
        $this->actingAs($doctor)->withSession(['_token' => 'test-token'])->post($url, $payload)->assertForbidden();
        $this->flushSession();
        $this->actingAs($patients[1])->withSession(['_token' => 'test-token'])->post($url, $payload)->assertNotFound();
        $this->flushSession();
        config(['mediqueue.mock_payments_enabled' => false]);
        $this->actingAs($patients[0])->withSession(['_token' => 'test-token'])->post($url, $payload)->assertNotFound();
        config(['mediqueue.mock_payments_enabled' => true]);
        CarbonImmutable::setTestNow('2026-11-16T20:36:00Z');
        $this->post($url, $payload)->assertForbidden();
    }

    public function test_payment_and_cancel_reject_injected_fields_and_forward_only_authorized_input(): void
    {
        $patient = User::where('role', 'PATIENT')->firstOrFail();
        $id = app(AppointmentService::class)->create($patient->id, DB::table('slots')->value('id'), 'http_payment_key_123');
        $url = URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $id]);
        $payload = ['_token' => 'test-token', 'scenario' => 'success'];
        foreach (['amount', 'status', 'paid', 'order_no', 'user_id'] as $field) {
            $this->actingAs($patient)->withSession(['_token' => 'test-token'])->post($url, $payload + [$field => '1'])->assertSessionHasErrors('appointment');
        }
        $this->post($url, array_replace($payload, ['scenario' => 'invented']))->assertSessionHasErrors('scenario');
        $this->post('/appointments/'.$id.'/cancel', ['_token' => 'test-token', 'reason_code' => 'ADMIN_CORRECTION'])->assertSessionHasErrors('appointment');
        $mock = $this->mock(AppointmentLifecycleService::class);
        $mock->shouldReceive('pay')->once()->with((int) $patient->id, $id, 'success')->andReturn('CONFIRMED');
        $this->post($url, $payload)->assertRedirect(route('appointments.show', $id));
        $mock->shouldReceive('cancel')->once()->with((int) $patient->id, $id)->andReturn('CANCELLED');
        $this->post('/appointments/'.$id.'/cancel', ['_token' => 'test-token'])->assertRedirect(route('appointments.show', $id));
    }

    public function test_admin_actions_require_role_confirmation_and_controlled_fields(): void
    {
        $patient = User::where('role', 'PATIENT')->firstOrFail();
        $admin = User::where('role', 'ADMIN')->firstOrFail();
        $id = app(AppointmentService::class)->create($patient->id, DB::table('slots')->value('id'), 'http_payment_key_123');
        $this->actingAs($patient)->get('/admin/appointments')->assertForbidden();
        $this->flushSession();
        $this->actingAs($admin)->get('/admin/appointments')->assertOk()->assertSee('模拟微信支付');
        $this->get('/admin/appointments/'.$id)->assertOk();
        $this->withSession(['_token' => 'test-token'])->post('/admin/appointments/'.$id.'/complete', ['_token' => 'test-token'])->assertSessionHasErrors('verified');
        $this->post('/admin/appointments/'.$id.'/cancel', ['_token' => 'test-token', 'reason_code' => 'PATIENT_REQUEST', 'reason' => '演示', 'verified_unseen' => '1'])->assertSessionHasErrors('reason_code');
        $this->post('/admin/appointments/'.$id.'/cancel', ['_token' => 'test-token', 'reason_code' => 'CLINIC_SUSPENDED', 'reason' => '演示'])->assertSessionHasErrors('verified_unseen');
        $this->post('/admin/appointments/'.$id.'/refund', ['_token' => 'test-token', 'scenario' => 'success', 'amount' => 1])->assertSessionHasErrors('appointment');
        config(['mediqueue.mock_payments_enabled' => false]);
        $this->post('/admin/appointments/'.$id.'/refund', ['_token' => 'test-token', 'scenario' => 'success'])->assertNotFound();
    }

    public function test_real_payment_cancellation_and_refund_retry_through_http(): void
    {
        $patient = User::where('role', 'PATIENT')->firstOrFail();
        $admin = User::where('role', 'ADMIN')->firstOrFail();
        $id = app(AppointmentService::class)->create($patient->id, DB::table('slots')->value('id'), 'http_real_payment_123');
        $url = URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $id]);
        $this->actingAs($patient)->withSession(['_token' => 'test-token'])->post($url, ['_token' => 'test-token', 'scenario' => 'unknown'])
            ->assertRedirect(route('appointments.show', $id))->assertSessionHas('status', '模拟支付结果未知，请刷新本预约核实后重试原操作（无实际扣款）。');
        $this->actingAs($patient)->withSession(['_token' => 'test-token'])->post($url, ['_token' => 'test-token', 'scenario' => 'success'])->assertRedirect(route('appointments.show', $id));
        $this->get('/appointments/'.$id)->assertOk()->assertSee('CONFIRMED')->assertSee('PAID');
        $this->post('/appointments/'.$id.'/cancel', ['_token' => 'test-token'])->assertRedirect(route('appointments.show', $id));
        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'CANCELLED']);
        $order = DB::table('payment_orders')->where('appointment_id', $id)->first();
        $refund = DB::table('refunds')->where('payment_order_id', $order->id)->first();
        $this->assertSame('REFUND_PENDING', $order->status);
        $this->assertSame('PENDING', $refund->status);
        $this->get('/appointments/'.$id)->assertOk()->assertSee('待处理（仅模拟）');
        $this->flushSession();
        $this->actingAs($admin)->withSession(['_token' => 'test-token'])->get('/admin/appointments/'.$id)->assertOk()->assertSee('演示患者甲')->assertSee('演示全科')->assertSee('演示医生甲')->assertSee('待处理（仅模拟）');
        $this->post('/admin/appointments/'.$id.'/refund', ['_token' => 'test-token', 'scenario' => 'failed'])->assertRedirect();
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'FAILED']);
        $this->get('/admin/appointments/'.$id)->assertOk()->assertSee('失败（仅模拟）')->assertSee('模拟退款被拒绝，可重试原退款。');
        $this->post('/admin/appointments/'.$id.'/refund', ['_token' => 'test-token', 'scenario' => 'success'])->assertRedirect();
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'SUCCEEDED']);
        $this->assertDatabaseHas('payment_orders', ['id' => $order->id, 'status' => 'REFUNDED']);
        $this->assertDatabaseCount('refunds', 1);
        $this->get('/admin/appointments/'.$id)->assertOk()->assertSee('成功（仅模拟）');
    }
}
