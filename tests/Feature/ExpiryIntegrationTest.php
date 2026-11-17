<?php

namespace Tests\Feature;

use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ExpiryIntegrationTest extends TestCase
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
    }

    public function test_on_demand_reclaim_scopes_patient_or_slot_and_keeps_old_request_identity(): void
    {
        $patients = DB::table('patients')->orderBy('id')->get();
        $slots = DB::table('slots')->orderBy('id')->get();
        $service = app(AppointmentService::class);
        $own = $service->create($patients[0]->user_id, $slots[0]->id, 'own-old');
        $target = $service->create($patients[1]->user_id, $slots[1]->id, 'target-old');
        $unrelated = $service->create($patients[2]->user_id, $slots[2]->id, 'unrelated-old');
        CarbonImmutable::setTestNow('2026-11-16T20:45:00Z');
        $new = $service->create($patients[0]->user_id, $slots[1]->id, 'new');
        foreach ([$own, $target] as $id) {
            $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'EXPIRED']);
            $this->assertDatabaseHas('payment_orders', ['appointment_id' => $id, 'status' => 'CLOSED']);
        }
        $this->assertDatabaseHas('appointments', ['id' => $unrelated, 'status' => 'PENDING_PAYMENT']);
        $this->assertDatabaseHas('appointments', ['id' => $new, 'status' => 'PENDING_PAYMENT']);
        $this->assertSame($own, $service->create($patients[0]->user_id, $slots[0]->id, 'own-old'));
        $this->assertSame(4, DB::table('appointments')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'APPOINTMENT_EXPIRED')->count());
    }

    public function test_failed_reclamation_never_makes_capacity_available_to_creation(): void
    {
        $patients = DB::table('patients')->orderBy('id')->get();
        $slot = DB::table('slots')->orderBy('id')->first();
        DB::table('slots')->where('id', $slot->id)->update(['capacity' => 1]);
        $old = app(AppointmentService::class)->create($patients[0]->user_id, $slot->id, 'old');
        CarbonImmutable::setTestNow('2026-11-16T20:45:00Z');
        $fault = true;
        DB::listen(function (QueryExecuted $event) use (&$fault): void {
            if ($fault && str_starts_with($event->sql, 'insert into `audit_logs`')) {
                $fault = false;
                throw new RuntimeException('Injected expiry audit failure');
            }
        });
        try {
            app(AppointmentService::class)->create($patients[1]->user_id, $slot->id, 'retry-new');
            $this->fail('Expiry failure cannot be treated as freed capacity.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected expiry audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('appointments', ['id' => $old, 'status' => 'PENDING_PAYMENT', 'released_at' => null]);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $old, 'status' => 'UNPAID']);
        foreach (['appointments', 'payment_orders', 'audit_logs'] as $table) {
            $this->assertSame(1, DB::table($table)->count());
        }
        $this->assertSame(1, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
        $new = app(AppointmentService::class)->create($patients[1]->user_id, $slot->id, 'retry-new');
        $this->assertNotSame($old, $new);
        $this->assertSame(1, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
    }

    public function test_command_and_minute_schedule_use_bounded_shared_reclaimer_without_mock_payment_switch(): void
    {
        $patient = DB::table('patients')->orderBy('id')->first();
        $slotId = DB::table('slots')->orderBy('id')->value('id');
        $id = app(AppointmentService::class)->create($patient->user_id, $slotId, 'timer');
        CarbonImmutable::setTestNow('2026-11-16T20:45:00Z');
        config(['mediqueue.mock_payments_enabled' => false]);
        $this->artisan('mediqueue:expire-appointments', ['--limit' => 1])->expectsOutput('已回收 1 条到期预约。')->assertSuccessful();
        $this->artisan('mediqueue:expire-appointments')->expectsOutput('已回收 0 条到期预约。')->assertSuccessful();
        $this->artisan('mediqueue:expire-appointments', ['--limit' => 0])->assertExitCode(2);
        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'EXPIRED']);
        $events = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains($event->command ?? '', 'mediqueue:expire-appointments')));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(0, app(AppointmentLifecycleService::class)->reclaim());
    }
}
