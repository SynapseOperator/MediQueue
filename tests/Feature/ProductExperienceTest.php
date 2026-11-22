<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductExperienceTest extends TestCase
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
        config(['mediqueue.mock_payments_enabled' => true]);
        $this->seed();
        $this->withoutVite();
    }

    public function test_confirmation_is_read_only_and_checkout_still_uses_the_original_protected_transaction(): void
    {
        $patient = User::where('username', 'demo_patient_1')->sole();
        $slot = DB::table('slots')->orderBy('id')->first();
        $key = 'P5_confirmation_original';
        $url = route('appointments.confirm', ['slot_id' => $slot->id, 'request_key' => $key]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($patient)->get($url)->assertOk()->assertSee('确认出诊信息')->assertSee('陈嘉禾')
            ->assertSeeText('最晚到就诊开始时')
            ->assertSee('value="'.$key.'"', false)->assertSee('12.34');
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'occupied_count' => 0]);
        $this->post(route('appointments.store'), ['slot_id' => $slot->id, 'request_key' => $key])->assertRedirect();
        $id = DB::table('appointments')->value('id');
        $this->get(route('appointments.checkout', $id))->assertOk()->assertSee('模拟收银台')->assertSee('无实际扣款');
        $pay = URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $id]);
        $this->post($pay, ['scenario' => 'success'])->assertRedirect(route('appointments.show', $id));
        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'occupied_count' => 1]);
        CarbonImmutable::setTestNow('2026-11-16T21:00:00Z');
        $this->get(route('appointments.checkout', $id))->assertOk()->assertDontSee('预约待回收');
        $this->flushSession();
        $this->actingAs(User::where('username', 'demo_patient_2')->sole())->get(route('appointments.checkout', $id))->assertNotFound();
        $this->flushSession();
        $this->actingAs(User::where('role', 'DOCTOR')->first())->get($url)->assertForbidden();
    }

    public function test_patient_history_filter_and_refund_details_never_expose_another_patient(): void
    {
        $patients = User::where('role', 'PATIENT')->orderBy('id')->get();
        $slot = DB::table('slots')->orderBy('id')->value('id');
        $own = app(AppointmentService::class)->create($patients[0]->id, $slot, 'own-history');
        $other = app(AppointmentService::class)->create($patients[1]->id, $slot, 'other-history');
        $lifecycle = app(AppointmentLifecycleService::class);
        $lifecycle->pay($patients[0]->id, $own);
        $lifecycle->cancel($patients[0]->id, $own);
        $lifecycle->refund(User::where('role', 'ADMIN')->value('id'), $own, 'failed');
        $this->actingAs($patients[0])->get('/appointments?status=CANCELLED')->assertOk()
            ->assertSee(DB::table('appointments')->find($own)->appointment_no)->assertDontSee(DB::table('appointments')->find($other)->appointment_no);
        $this->get('/appointments?status=PENDING_PAYMENT')->assertOk()->assertDontSee(DB::table('appointments')->find($own)->appointment_no);
        $this->get('/appointments/'.$own)->assertOk()->assertSeeText('退款失败')->assertSee('模拟退款被拒绝');
        $this->get('/appointments/'.$other)->assertNotFound();
        $this->get('/appointments?status=INVENTED')->assertRedirect()->assertSessionHasErrors('status');
    }

    public function test_confirmation_old_input_belongs_only_to_the_original_slot(): void
    {
        $patient = User::where('username', 'demo_patient_1')->sole();
        $slots = DB::table('slots')->orderBy('id')->get();
        $this->actingAs($patient)->withSession(['_old_input' => ['slot_id' => $slots[0]->id, 'request_key' => 'original_slot_A_key']])
            ->get(route('appointments.confirm', ['slot_id' => $slots[1]->id, 'request_key' => 'original_slot_B_key']))
            ->assertOk()->assertSee('value="original_slot_B_key"', false)->assertDontSee('value="original_slot_A_key"', false);
        $this->withSession(['_old_input' => ['slot_id' => $slots[0]->id, 'request_key' => 'original_slot_A_key']])
            ->get(route('appointments.confirm', ['slot_id' => $slots[0]->id]))->assertOk()->assertSee('value="original_slot_A_key"', false);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_doctor_roster_contains_only_own_confirmed_or_completed_patients_and_minimal_fields(): void
    {
        $doctors = DB::table('doctors')->orderBy('id')->get();
        $slots = DB::table('slots')->orderBy('id')->get();
        $patients = User::where('role', 'PATIENT')->orderBy('id')->get();
        $own = app(AppointmentService::class)->create($patients[0]->id, $slots[0]->id, 'roster-own');
        app(AppointmentLifecycleService::class)->pay($patients[0]->id, $own);
        app(AppointmentService::class)->create($patients[1]->id, $slots[1]->id, 'roster-pending');
        $other = app(AppointmentService::class)->create($patients[2]->id, $slots[2]->id, 'roster-other');
        app(AppointmentLifecycleService::class)->pay($patients[2]->id, $other);
        $this->actingAs(User::find($doctors[0]->user_id))->get('/doctor/schedules/'.$slots[0]->schedule_id)->assertOk()
            ->assertSee('陈嘉禾')->assertDontSee('徐明远')->assertDontSee('宋安宁')
            ->assertDontSee('010-00000001')->assertDontSee(DB::table('payment_orders')->where('appointment_id', $own)->value('order_no'))
            ->assertDontSee('name="capacity"', false);
        $this->get('/doctor/schedules/'.$slots[2]->schedule_id)->assertNotFound();
        $this->get('/doctor/workbench?date=2026-12-01')->assertOk()->assertDontSee('09:00');
        $this->putJson('/admin/schedules/'.$slots[0]->schedule_id, ['status' => 'CLOSED'])->assertForbidden();
    }

    public function test_admin_attention_filters_distinguish_refund_and_suspension_without_changing_records(): void
    {
        $patients = User::where('role', 'PATIENT')->orderBy('id')->get();
        $slots = DB::table('slots')->orderBy('id')->get();
        $refund = app(AppointmentService::class)->create($patients[0]->id, $slots[0]->id, 'filter-refund');
        app(AppointmentLifecycleService::class)->pay($patients[0]->id, $refund);
        app(AppointmentLifecycleService::class)->cancel($patients[0]->id, $refund);
        $suspended = app(AppointmentService::class)->create($patients[1]->id, $slots[2]->id, 'filter-suspended');
        DB::table('schedules')->where('id', $slots[2]->schedule_id)->update(['status' => 'SUSPENDED', 'suspend_reason' => '演示暂停']);
        $refundNo = DB::table('appointments')->find($refund)->appointment_no;
        $suspendedNo = DB::table('appointments')->find($suspended)->appointment_no;
        $this->actingAs(User::where('role', 'ADMIN')->sole())->get('/admin/appointments?attention=refund')->assertOk()->assertSee($refundNo)->assertDontSee($suspendedNo);
        $this->get('/admin/appointments?attention=suspended')->assertOk()->assertSee($suspendedNo)->assertDontSee($refundNo);
        $this->assertDatabaseHas('appointments', ['id' => $suspended, 'status' => 'PENDING_PAYMENT']);
        $this->flushSession();
        $this->actingAs($patients[0])->get('/admin/appointments?attention=refund')->assertForbidden();
    }
}
