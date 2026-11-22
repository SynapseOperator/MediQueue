<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack();
            CarbonImmutable::setTestNow();
        });
        CarbonImmutable::setTestNow('2026-11-16T15:59:00Z');
        config(['mediqueue.mock_payments_enabled' => true]);
        $this->seed();
        $this->withoutVite();
    }

    public function test_metrics_keep_creation_visit_and_settlement_grains_separate_at_shanghai_midnight(): void
    {
        $patients = DB::table('patients')->orderBy('id')->pluck('user_id');
        $admin = DB::table('users')->where('role', 'ADMIN')->value('id');
        $slots = DB::table('slots')->orderBy('id')->get();
        $booking = app(AppointmentService::class);
        $life = app(AppointmentLifecycleService::class);
        // A: created on Shanghai Nov 16, paid exactly at Nov 17 midnight, refunded on Nov 18.
        $a = $booking->create($patients[0], $slots[0]->id, 'analytics-a');
        CarbonImmutable::setTestNow('2026-11-16T16:00:00Z');
        $life->pay($patients[0], $a);
        CarbonImmutable::setTestNow('2026-11-16T16:01:00Z');
        $life->cancel($patients[0], $a);
        // B and C share one slot: its capacity must never be counted twice.
        CarbonImmutable::setTestNow('2026-11-16T16:02:00Z');
        $b = $booking->create($patients[1], $slots[0]->id, 'analytics-b');
        $life->pay($patients[1], $b);
        CarbonImmutable::setTestNow('2026-11-16T16:03:00Z');
        $c = $booking->create($patients[2], $slots[0]->id, 'analytics-c');
        $life->pay($patients[2], $c);
        CarbonImmutable::setTestNow('2026-11-16T16:04:00Z');
        $d = $booking->create($patients[0], $slots[1]->id, 'analytics-d');
        $life->pay($patients[0], $d);
        $life->cancel($patients[0], $d);
        $life->refund($admin, $d, 'failed');
        CarbonImmutable::setTestNow('2026-11-16T16:07:00Z');
        $e = $booking->create($patients[0], $slots[2]->id, 'analytics-e');
        CarbonImmutable::setTestNow('2026-11-16T16:08:00Z');
        $f = $booking->create($patients[1], $slots[3]->id, 'analytics-f');
        CarbonImmutable::setTestNow('2026-11-16T16:23:00Z');
        $life->expire($e);
        DB::table('schedules')->where('id', $slots[2]->schedule_id)->update(['status' => 'SUSPENDED', 'suspend_reason' => '合成统计停诊']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($slots[0]->starts_at, 'UTC'));
        $life->complete($admin, $c);
        CarbonImmutable::setTestNow('2026-11-17T16:00:00Z');
        $life->refund($admin, $a);

        $report = app(AnalyticsService::class)->report('2026-11-17', '2026-11-17');
        $s = $report['summary'];
        foreach (['created_count' => 5, 'cohort_cancelled' => 1, 'confirmed_count' => 1, 'completed_count' => 1,
            'paid_valid_count' => 2, 'capacity' => 10, 'occupied_count' => 3, 'pending_refunds' => 1,
            'suspended_count' => 1, 'unverified_count' => 1, 'occupancy_mismatches' => 0] as $field => $expected) {
            $this->assertSame($expected, (int) $s->$field, $field);
        }
        $this->assertSame('49.36', $s->simulated_paid);
        $this->assertSame('0.00', $s->simulated_refunded);
        $this->assertSame('49.36', $s->simulated_net);
        $this->assertSame([$d], $report['pendingRefunds']->pluck('appointment_id')->all());
        $this->assertSame([$f], $report['suspended']->pluck('appointment_id')->all());
        $this->assertSame([$b], $report['unverified']->pluck('appointment_id')->all());
        $trend = app(AnalyticsService::class)->report('2026-11-16', '2026-11-17')['creationTrend'];
        $this->assertSame(['2026-11-16', '2026-11-17'], $trend->pluck('creation_date')->all());
        $this->assertSame([1, 5], $trend->pluck('appointment_count')->all());
        $departments = collect($report['departments'])->keyBy('department_code');
        $this->assertSame(5, (int) $departments['CARD']->capacity);
        $this->assertSame(2, (int) $departments['CARD']->occupied_count);
        $this->assertSame(2, (int) $departments['CARD']->cancelled);
        $this->assertSame(1, (int) $departments['PED']->pending_payment);
        $this->assertSame(1, (int) $departments['PED']->expired);

        // A later doctor affiliation must not rewrite historical schedule department statistics.
        $schedule = DB::table('schedules')->find($slots[0]->schedule_id);
        $otherDepartment = DB::table('schedules')->find($slots[2]->schedule_id)->department_id;
        DB::table('doctors')->where('id', $schedule->doctor_id)->update(['department_id' => $otherDepartment]);
        $historical = app(AnalyticsService::class)->report('2026-11-17', '2026-11-17');
        $this->assertEquals($report['departments'], $historical['departments']);

        $later = app(AnalyticsService::class)->report('2026-11-18', '2026-11-18')['summary'];
        $this->assertSame(0, (int) $later->created_count);
        $this->assertSame(0, (int) $later->capacity);
        $this->assertSame('0.00', $later->simulated_paid);
        $this->assertSame('12.34', $later->simulated_refunded);
        $this->assertSame('-12.34', $later->simulated_net);
        $this->assertSame(1, (int) $later->pending_refunds);
    }

    public function test_empty_date_range_and_zero_denominators_render_without_invented_rates(): void
    {
        $admin = User::where('role', 'ADMIN')->firstOrFail();
        $response = $this->actingAs($admin)->get('/admin/analytics?from=2026-01-01&to=2026-01-01');
        $response->assertOk()->assertSee('模拟净额')->assertSee('¥0.00')->assertSee('分母为零')->assertSee('—')->assertSee('暂无创建趋势');
        $s = $response->viewData('summary');
        $this->assertSame(0, (int) $s->created_count);
        $this->assertSame(0, (int) $s->capacity);
        $this->assertSame(0, (int) $s->occupancy_mismatches);
        $this->assertSame(2, count($response->viewData('departments')));
        $this->assertTrue($response->viewData('creationTrend')->isEmpty());
    }

    public function test_analytics_dashboard_and_audit_require_active_admin(): void
    {
        foreach (['/admin', '/admin/analytics', '/admin/audit'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach (['PATIENT', 'DOCTOR'] as $role) {
            $this->flushSession();
            $this->actingAs(User::where('role', $role)->firstOrFail());
            foreach (['/admin', '/admin/analytics', '/admin/audit'] as $url) {
                $this->get($url)->assertForbidden();
            }
        }
        $this->flushSession();
        $admin = User::where('role', 'ADMIN')->firstOrFail();
        $this->actingAs($admin);
        foreach (['/admin', '/admin/analytics', '/admin/audit'] as $url) {
            $this->get($url)->assertOk();
        }
        $admin->update(['status' => 'DISABLED']);
        $this->get('/admin/analytics')->assertRedirect('/login');
    }

    public function test_date_and_audit_filters_reject_invalid_types_and_values(): void
    {
        $this->actingAs(User::where('role', 'ADMIN')->firstOrFail());
        foreach (['from[]=2026-11-17', 'from=invalid', 'from=2026-11-18&to=2026-11-17'] as $query) {
            $this->getJson('/admin/analytics?'.$query)->assertUnprocessable()->assertJsonValidationErrors('from');
        }
        foreach (['action=RAW_SQL', 'action[]=PAYMENT_CONFIRMED', 'target_type=sessions', 'actor_type=ADMIN', 'target_id=-1'] as $query) {
            $this->getJson('/admin/audit?'.$query)->assertUnprocessable();
        }
    }

    public function test_audit_is_paginated_filtered_and_never_projects_sensitive_json(): void
    {
        $patientId = DB::table('patients')->value('id');
        for ($i = 0; $i < 25; $i++) {
            DB::table('audit_logs')->insert([
                'actor_type' => 'SYSTEM', 'operator_id' => null, 'action' => 'PATIENT_PROFILE_UPDATED',
                'target_type' => 'patients', 'target_id' => $patientId,
                'after_state' => json_encode(['password' => 'DO-NOT-DISPLAY-SECRET', 'phone' => 'DO-NOT-DISPLAY-PHONE']),
                'created_at' => '2026-11-16 15:59:59.999',
            ]);
        }
        DB::table('audit_logs')->insert(['actor_type' => 'SYSTEM', 'action' => 'PATIENT_PROFILE_UPDATED',
            'target_type' => 'patients', 'target_id' => $patientId, 'created_at' => '2026-11-16 16:00:00.000']);
        $query = 'from=2026-11-16&to=2026-11-16&action=PATIENT_PROFILE_UPDATED&actor_type=SYSTEM&target_type=patients&target_id='.$patientId;
        $this->actingAs(User::where('role', 'ADMIN')->firstOrFail());
        $response = $this->get('/admin/audit?'.$query)->assertOk()->assertDontSee('DO-NOT-DISPLAY-SECRET')->assertDontSee('DO-NOT-DISPLAY-PHONE');
        $logs = $response->viewData('logs');
        $this->assertSame(25, $logs->total());
        $this->assertCount(20, $logs->items());
        $this->assertEqualsCanonicalizing(['id', 'created_at', 'actor_type', 'operator_id', 'action', 'target_type', 'target_id'], array_keys((array) $logs->items()[0]));
        $second = $this->get('/admin/audit?'.$query.'&page=2')->assertOk();
        $this->assertCount(5, $second->viewData('logs')->items());
        $this->get('/admin/audit?from=2026-11-16&to=2026-11-16&action=PAYMENT_CONFIRMED')->assertOk()->assertSee('此条件下暂无审计记录');
    }

    public function test_audit_discloses_only_known_status_codes_in_closed_details(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'actor_type' => 'SYSTEM', 'action' => 'PAYMENT_CONFIRMED', 'target_type' => 'appointments', 'target_id' => 123,
            'before_state' => json_encode(['status' => 'PRIVATE-FREE-TEXT', 'phone' => 'PRIVATE-CONTACT']),
            'after_state' => json_encode(['status' => 'CONFIRMED', 'password' => 'PRIVATE-SECRET']),
            'created_at' => '2026-11-16 12:00:00.000',
        ]);
        $response = $this->actingAs(User::where('role', 'ADMIN')->sole())
            ->get('/admin/audit?from=2026-11-16&to=2026-11-16&target_id=123')->assertOk();
        $response->assertSee('<details><summary', false)->assertSeeText('变更后')->assertSeeText('已确认')
            ->assertDontSee('PRIVATE-FREE-TEXT')->assertDontSee('PRIVATE-CONTACT')->assertDontSee('PRIVATE-SECRET');
        $change = $response->viewData('changes')->get($id);
        $this->assertNull($change->before_status);
        $this->assertSame('CONFIRMED', $change->after_status);
    }

    public function test_occupancy_mismatch_is_visible_without_repairing_business_rows(): void
    {
        $slot = DB::table('slots')->orderBy('id')->first();
        DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 1]);
        $report = app(AnalyticsService::class)->report('2026-11-17', '2026-11-17');
        $this->assertSame(1, (int) $report['summary']->occupancy_mismatches);
        $this->assertSame(1, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
        $this->assertDatabaseCount('appointments', 0);
        $this->actingAs(User::where('role', 'ADMIN')->firstOrFail())->get('/admin/analytics?from=2026-11-17&to=2026-11-17')
            ->assertOk()->assertSee('待核对')->assertDontSee('10.0%')->assertDontSee('20.0%');
    }
}
