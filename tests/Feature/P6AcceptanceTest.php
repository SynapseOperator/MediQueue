<?php

namespace Tests\Feature;

use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class P6AcceptanceTest extends TestCase
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
    }

    public function test_real_cancellation_allows_new_key_rebooking_without_reviving_history(): void
    {
        $patient = DB::table('patients')->orderBy('id')->value('user_id');
        $slot = DB::table('slots')->orderBy('id')->value('id');
        $booking = app(AppointmentService::class);
        $old = $booking->create($patient, $slot, 'p6-cancel-original');
        $this->assertSame('CANCELLED', app(AppointmentLifecycleService::class)->cancel($patient, $old));
        $this->assertDatabaseHas('slots', ['id' => $slot, 'occupied_count' => 0]);
        $new = $booking->create($patient, $slot, 'p6-cancel-new-key');
        $this->assertNotSame($old, $new);
        $this->assertSame($old, $booking->create($patient, $slot, 'p6-cancel-original'));
        $this->assertDatabaseHas('appointments', ['id' => $old, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $old, 'status' => 'CLOSED']);
        $this->assertDatabaseHas('appointments', ['id' => $new, 'status' => 'PENDING_PAYMENT']);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $new, 'status' => 'UNPAID']);
        $this->assertDatabaseHas('slots', ['id' => $slot, 'occupied_count' => 1]);
        $this->assertDatabaseCount('appointments', 2);
        $this->assertDatabaseCount('payment_orders', 2);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'APPOINTMENT_CREATED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'APPOINTMENT_CANCELLED')->count());
    }

    public function test_new_booking_uses_changed_price_while_both_original_snapshots_remain(): void
    {
        $patients = DB::table('patients')->orderBy('id')->pluck('user_id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $booking = app(AppointmentService::class);
        $old = $booking->create($patients[0], $slot->id, 'p6-price-original');
        // Management validation is covered in HospitalResourcesTest; change only the current-price fixture here.
        DB::table('slots')->where('id', $slot->id)->update(['fee_amount' => '40.00']);
        $new = $booking->create($patients[1], $slot->id, 'p6-price-new-key');
        foreach ([$old => $slot->fee_amount, $new => '40.00'] as $id => $amount) {
            $this->assertSame($amount, DB::table('appointments')->where('id', $id)->value('fee_amount'));
            $this->assertSame($amount, DB::table('payment_orders')->where('appointment_id', $id)->value('amount'));
        }
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'fee_amount' => '40.00', 'occupied_count' => 2]);
        $this->assertDatabaseCount('appointments', 2);
        $this->assertDatabaseCount('payment_orders', 2);
    }

    public function test_patient_cannot_cancel_still_confirmed_booking_after_start(): void
    {
        $patient = DB::table('patients')->orderBy('id')->value('user_id');
        $slot = DB::table('slots')->orderBy('id')->first();
        $id = app(AppointmentService::class)->create($patient, $slot->id, 'p6-cancel-after-start');
        $lifecycle = app(AppointmentLifecycleService::class);
        $this->assertSame('CONFIRMED', $lifecycle->pay($patient, $id));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($slot->starts_at, 'UTC')->addMillisecond());
        $before = [DB::table('appointments')->find($id), DB::table('payment_orders')->where('appointment_id', $id)->sole(), DB::table('slots')->find($slot->id), DB::table('audit_logs')->orderBy('id')->get()->toArray()];
        try {
            $lifecycle->cancel($patient, $id);
            $this->fail('A patient must not cancel a confirmed booking after its start.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment', $exception->errors());
        }
        $this->assertEquals($before, [DB::table('appointments')->find($id), DB::table('payment_orders')->where('appointment_id', $id)->sole(), DB::table('slots')->find($slot->id), DB::table('audit_logs')->orderBy('id')->get()->toArray()]);
        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'CONFIRMED', 'cancelled_at' => null, 'completed_at' => null]);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $id, 'status' => 'PAID']);
        $this->assertDatabaseHas('slots', ['id' => $slot->id, 'occupied_count' => 1]);
        $this->assertDatabaseCount('refunds', 0);
    }
}
