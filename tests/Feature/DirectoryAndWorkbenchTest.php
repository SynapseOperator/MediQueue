<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectoryAndWorkbenchTest extends TestCase
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
    }

    public function test_public_pages_display_only_open_resources_without_private_fields(): void
    {
        $doctor = DB::table('doctors')->orderBy('id')->first();
        $this->get('/departments')->assertOk()->assertSee('演示全科');
        $this->get('/doctors')->assertOk()->assertSee('演示医生甲')->assertDontSee('demo_doctor_1')->assertDontSee('DEMO-PATIENT');
        $this->get('/doctors/'.$doctor->id)->assertOk()->assertSee('09:00')->assertSee('12.34')->assertSee('付款将在下一阶段开放');
        $this->get('/doctors?department='.$doctor->department_id)->assertOk()->assertSee('演示医生甲')->assertDontSee('演示医生乙');
        DB::table('departments')->where('id', $doctor->department_id)->update(['status' => 'DISABLED']);
        $this->get('/departments')->assertOk()->assertDontSee('演示全科');
        $this->get('/doctors')->assertOk()->assertDontSee('演示医生甲');
        $this->get('/doctors/'.$doctor->id)->assertNotFound();
    }

    public function test_inactive_doctors_and_accounts_are_not_publicly_exposed(): void
    {
        $doctors = DB::table('doctors')->orderBy('id')->get();
        DB::table('doctors')->where('id', $doctors[0]->id)->update(['status' => 'DISABLED']);
        DB::table('users')->where('id', $doctors[1]->user_id)->update(['status' => 'DISABLED']);
        $this->get('/doctors')->assertOk()->assertSee('暂无开放医生')->assertDontSee('演示医生甲')->assertDontSee('演示医生乙');
        foreach ($doctors as $doctor) {
            $this->get('/doctors/'.$doctor->id)->assertNotFound();
        }
    }

    public function test_unpublished_suspended_closed_and_started_slots_are_hidden(): void
    {
        $schedule = DB::table('schedules')->orderBy('id')->first();
        foreach (['DRAFT', 'SUSPENDED', 'CLOSED'] as $status) {
            DB::table('schedules')->where('id', $schedule->id)->update(['status' => $status, 'suspend_reason' => '演示停诊说明']);
            $this->get('/doctors/'.$schedule->doctor_id)->assertOk()->assertDontSee('12.34')->assertDontSee('演示停诊说明');
        }
        DB::table('schedules')->where('id', $schedule->id)->update(['status' => 'OPEN']);
        $firstSlot = DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('starts_at')->first();
        DB::table('slots')->where('id', $firstSlot->id)->update(['status' => 'CLOSED']);
        $this->get('/doctors/'.$schedule->doctor_id)->assertOk()->assertDontSee('09:00')->assertSee('09:30');
        DB::table('slots')->where('id', $firstSlot->id)->update(['status' => 'OPEN']);
        CarbonImmutable::setTestNow('2026-11-18T01:00:00Z');
        $this->get('/doctors/'.$schedule->doctor_id)->assertOk()->assertDontSee('09:00')->assertSee('09:30');
    }

    public function test_public_filter_and_html_escaping_are_enforced(): void
    {
        $doctor = DB::table('doctors')->orderBy('id')->first();
        DB::table('doctors')->where('id', $doctor->id)->update(['introduction' => '<script>alert("demo")</script>']);
        $this->get('/doctors/'.$doctor->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false);
        $this->getJson('/doctors?department[]=1')->assertUnprocessable();
        $this->getJson('/doctors/'.$doctor->id.'?date=2026-02-30')->assertUnprocessable();
        $this->getJson('/doctors/'.$doctor->id.'?date=2026-11-30')->assertUnprocessable();
        $this->get('/doctors/'.$doctor->id.'?date=2026-11-18')->assertOk()->assertSee('12.34');
    }

    public function test_doctor_workbench_scopes_private_schedules_to_the_signed_in_doctor(): void
    {
        $doctor = User::where('username', 'demo_doctor_1')->firstOrFail();
        $schedules = DB::table('schedules')->orderBy('id')->get();
        DB::table('schedules')->where('id', $schedules[1]->id)->update(['status' => 'SUSPENDED', 'suspend_reason' => '其他医生私有说明']);
        $this->actingAs($doctor)->get('/doctor/workbench?doctor_id='.$schedules[1]->doctor_id)
            ->assertOk()->assertSee('演示医生甲')->assertDontSee('演示医生乙')->assertDontSee('其他医生私有说明');
        $this->get('/doctor/schedules/'.$schedules[0]->id)->assertOk()->assertSee('12.34');
        $this->get('/doctor/schedules/'.$schedules[1]->id)->assertNotFound();
        $this->putJson('/admin/schedules/'.$schedules[0]->id, ['status' => 'CLOSED'])->assertForbidden();
        $this->postJson('/admin/slots', [])->assertForbidden();
        $this->get('/patient/profile')->assertForbidden();
    }

    public function test_role_pages_and_forms_render_for_their_owners(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false)->assertSee('autocomplete="current-password"', false);
        $this->get('/register')->assertOk()->assertDontSee('name="role"', false);
        $this->get('/doctor/workbench')->assertRedirect(route('login'));
        $this->get('/admin/resources')->assertRedirect(route('login'));
        $this->actingAs(User::where('username', 'demo_patient_1')->firstOrFail());
        $this->get('/patient/profile')->assertOk()->assertSee('演示患者甲')->assertDontSee('演示患者乙');
        $this->get('/doctor/workbench')->assertForbidden();
        $this->get('/admin/resources')->assertForbidden();
        $this->app['session']->flush();
        $this->actingAs(User::where('username', 'demo_admin')->firstOrFail());
        $this->get('/admin/resources')->assertOk()->assertSee('创建医生及账号')->assertSee('配置时段')->assertDontSee('password_hash');
        $this->get('/doctor/workbench')->assertForbidden();
        $this->get('/patient/profile')->assertForbidden();
    }

    public function test_invalid_array_input_and_missing_times_render_errors_without_a_server_error(): void
    {
        $this->from('/register')->post('/register', ['username' => ['not-a-string']])->assertRedirect('/register')->assertSessionHasErrors('username');
        $this->get('/register')->assertOk();
        $this->from('/login')->post('/login', ['username' => ['not-a-string']])->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->actingAs(User::where('username', 'demo_patient_1')->firstOrFail());
        $this->from('/patient/profile')->patch('/patient/profile', ['full_name' => ['not-a-string'], 'phone' => ['not-a-string']])->assertSessionHasErrors();
        $this->get('/patient/profile')->assertOk();
        $this->app['session']->flush();
        $this->actingAs(User::where('username', 'demo_admin')->firstOrFail());
        foreach (['schedule' => 'schedules', 'slot' => 'slots', 'department' => 'departments', 'doctor' => 'doctors'] as $singular => $plural) {
            $this->from('/admin/resources')->post('/admin/'.$plural, [
                '_form' => $singular.'-new', 'ends_at' => ['not-a-string'],
                'name' => ['not-a-string'], 'full_name' => ['not-a-string'], 'department_id' => ['not-a-string'],
            ])->assertRedirect('/admin/resources')->assertSessionHasErrors();
            $this->get('/admin/resources')->assertOk();
        }
    }

    public function test_admin_old_input_only_refills_the_form_that_failed(): void
    {
        $this->actingAs(User::where('username', 'demo_admin')->firstOrFail());
        $this->from('/admin/resources')->post('/admin/departments', [
            '_form' => 'department-new', 'name' => '仅回填此表单', 'status' => 'ACTIVE',
        ])->assertSessionHasErrors('code');
        $html = $this->get('/admin/resources')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'value="仅回填此表单"'));
        $this->assertStringContainsString('value="演示全科"', $html);
        $this->assertStringContainsString('value="演示专科"', $html);
    }
}
