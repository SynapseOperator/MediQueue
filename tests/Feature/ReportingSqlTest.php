<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportingSqlTest extends TestCase
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
    }

    public function test_overlap_function_enforces_half_open_nonempty_intervals(): void
    {
        $this->assertSame(0, DB::selectOne("SELECT fn_intervals_overlap('2026-11-18 01:00:00.000','2026-11-18 01:30:00.000','2026-11-18 01:30:00.000','2026-11-18 02:00:00.000') AS result")->result);
        $this->assertSame(1, DB::selectOne("SELECT fn_intervals_overlap('2026-11-18 01:00:00.000','2026-11-18 01:30:00.001','2026-11-18 01:30:00.000','2026-11-18 02:00:00.000') AS result")->result);
        foreach ([[null, '2026-11-18 01:30:00', '2026-11-18 01:00:00', '2026-11-18 02:00:00'], ['2026-11-18 01:00:00', '2026-11-18 01:00:00', '2026-11-18 01:00:00', '2026-11-18 02:00:00'], ['2026-11-18 02:00:00', '2026-11-18 01:00:00', '2026-11-18 01:00:00', '2026-11-18 02:00:00']] as $values) {
            try {
                DB::select('SELECT fn_intervals_overlap(?, ?, ?, ?)', $values);
                $this->fail('Invalid interval accepted');
            } catch (QueryException $e) {
                $this->assertSame('45000', $e->errorInfo[0]);
            }
        }
    }

    public function test_statistics_and_occupancy_count_without_join_multiplication(): void
    {
        $slot = DB::table('slots')->orderBy('id')->first();
        $schedule = DB::table('schedules')->find($slot->schedule_id);
        $patients = DB::table('patients')->orderBy('id')->get();
        $now = '2026-11-16 20:30:00.000';
        foreach ($patients->take(2) as $index => $patient) {
            $id = DB::table('appointments')->insertGetId(['appointment_no' => 'REPORT-'.$index, 'patient_id' => $patient->id, 'slot_id' => $slot->id, 'request_key' => 'report-'.$index, 'status' => 'PENDING_PAYMENT', 'fee_amount' => '12.34', 'expires_at' => '2026-11-16 20:45:00.000', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('payment_orders')->insert(['appointment_id' => $id, 'order_no' => 'REPORT-ORDER-'.$index, 'amount' => '12.34', 'status' => 'UNPAID', 'currency' => 'CNY', 'channel' => 'MOCK_WECHAT', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 2]);
        $view = DB::table('v_slot_occupancy_check')->where('slot_id', $slot->id)->sole();
        $this->assertEquals(2, $view->actual_occupied_count);
        $this->assertEquals(0, $view->difference);
        $this->assertSame(2, DB::table('v_appointment_details')->where('slot_id', $slot->id)->count());
        $rows = DB::select('CALL sp_department_statistics(?, ?)', [$schedule->work_date, $schedule->work_date]);
        $row = collect($rows)->firstWhere('department_id', $schedule->department_id);
        $this->assertEquals(5, $row->capacity);
        $this->assertEquals(2, $row->pending_payment);
        $this->assertEquals(2, $row->occupied_count);
        $outside = collect(DB::select('CALL sp_department_statistics(?, ?)', ['2026-11-19', '2026-11-19']))->firstWhere('department_id', $schedule->department_id);
        $this->assertEquals(0, $outside->capacity);
    }

    public function test_occupancy_difference_is_signed_and_does_not_repair_data(): void
    {
        $slot = DB::table('slots')->orderBy('id')->first();
        $patient = DB::table('patients')->first();
        DB::table('appointments')->insert(['appointment_no' => 'REPORT-EXPIRED-PENDING', 'patient_id' => $patient->id, 'slot_id' => $slot->id, 'request_key' => 'expired-pending', 'status' => 'PENDING_PAYMENT', 'fee_amount' => '12.34', 'created_at' => '2026-11-16 20:00:00.000', 'updated_at' => '2026-11-16 20:00:00.000', 'expires_at' => '2026-11-16 20:15:00.000']);
        $row = DB::table('v_slot_occupancy_check')->where('slot_id', $slot->id)->sole();
        $this->assertEquals(1, $row->actual_occupied_count);
        $this->assertEquals(-1, $row->difference);
        $this->assertSame(0, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
        DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 2]);
        $this->assertEquals(1, DB::table('v_slot_occupancy_check')->where('slot_id', $slot->id)->value('difference'));
        $this->assertSame(2, DB::table('slots')->where('id', $slot->id)->value('occupied_count'));
    }

    public function test_overlap_function_inclusive_cases(): void
    {
        foreach ([
            ['01:00:00', '02:00:00', '01:00:00', '02:00:00', 1],
            ['01:00:00', '02:00:00', '01:30:00', '02:30:00', 1],
            ['01:00:00', '03:00:00', '01:30:00', '02:00:00', 1],
            ['01:00:00', '02:00:00', '02:00:00', '03:00:00', 0],
            ['01:00:00', '02:00:00', '03:00:00', '04:00:00', 0],
        ] as [$a, $b, $c, $d, $expected]) {
            $values = array_map(fn ($time) => '2026-11-18 '.$time, [$a, $b, $c, $d]);
            $this->assertEquals($expected, DB::selectOne('SELECT fn_intervals_overlap(?, ?, ?, ?) AS result', $values)->result);
        }
    }

    public function test_procedure_rejects_invalid_date_range(): void
    {
        foreach ([[null, '2026-11-18'], ['2026-11-19', '2026-11-18']] as $dates) {
            try {
                DB::select('CALL sp_department_statistics(?, ?)', $dates);
                $this->fail('Invalid dates accepted');
            } catch (QueryException $e) {
                $this->assertSame('45000', $e->errorInfo[0]);
            }
        }
    }

    public function test_all_statuses_and_refund_details_are_aggregated_by_work_date(): void
    {
        $slot = DB::table('slots')->orderBy('id')->first();
        $schedule = DB::table('schedules')->find($slot->schedule_id);
        $patient = DB::table('patients')->first();
        $admin = DB::table('users')->where('username', 'demo_admin')->value('id');
        $now = '2026-11-16 20:30:00.000';
        foreach (['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED', 'CANCELLED', 'EXPIRED'] as $status) {
            $row = ['appointment_no' => 'REPORT-'.$status, 'patient_id' => $patient->id, 'slot_id' => $slot->id, 'request_key' => 'state-'.$status, 'status' => $status, 'fee_amount' => '12.34', 'created_at' => $now, 'updated_at' => $now, 'expires_at' => '2026-11-16 20:45:00.000'];
            if (in_array($status, ['CONFIRMED', 'COMPLETED', 'CANCELLED'], true)) {
                $row['confirmed_at'] = $now;
            }
            if ($status === 'COMPLETED') {
                $row += ['completed_at' => $now, 'completed_by' => $admin];
            }
            if (in_array($status, ['CANCELLED', 'EXPIRED'], true)) {
                $row['released_at'] = $now;
            }
            if ($status === 'CANCELLED') {
                $row += ['cancelled_at' => $now, 'cancelled_by' => $admin, 'cancel_reason_code' => 'PATIENT_REQUEST'];
            }
            $id = DB::table('appointments')->insertGetId($row);
            $paid = in_array($status, ['CONFIRMED', 'COMPLETED', 'CANCELLED'], true);
            $order = ['appointment_id' => $id, 'order_no' => 'REPORT-O-'.$status, 'amount' => '12.34', 'currency' => 'CNY', 'channel' => 'MOCK_WECHAT', 'status' => $status === 'CANCELLED' ? 'REFUNDED' : ($paid ? 'PAID' : ($status === 'EXPIRED' ? 'CLOSED' : 'UNPAID')), 'created_at' => $now, 'updated_at' => $now];
            if ($paid) {
                $order['paid_at'] = $now;
            }
            if ($status === 'EXPIRED') {
                $order['closed_at'] = $now;
            }
            $orderId = DB::table('payment_orders')->insertGetId($order);
            if ($status === 'CANCELLED') {
                DB::table('refunds')->insert(['payment_order_id' => $orderId, 'refund_no' => 'REPORT-REFUND', 'amount' => '12.34', 'status' => 'SUCCEEDED', 'reason_code' => 'PATIENT_REQUEST', 'requested_by' => $admin, 'succeeded_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                $detail = DB::table('v_appointment_details')->where('appointment_id', $id)->sole();
                $this->assertSame($patient->user_id, $detail->patient_user_id);
                $this->assertSame($schedule->doctor_id, $detail->doctor_id);
                $this->assertSame($schedule->department_id, $detail->department_id);
                $this->assertSame($orderId, $detail->payment_order_id);
                $this->assertSame('SUCCEEDED', $detail->refund_status);
                $this->assertSame('12.34', $detail->refund_amount);
            }
        }
        DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 3]);
        $rows = collect(DB::select('CALL sp_department_statistics(?, ?)', [$schedule->work_date, $schedule->work_date]));
        $row = $rows->firstWhere('department_id', $schedule->department_id);
        foreach (['pending_payment', 'confirmed', 'completed', 'cancelled', 'expired'] as $field) {
            $this->assertEquals(1, $row->$field);
        }
        $this->assertEquals(5, $row->capacity);
        $this->assertEquals(3, $row->occupied_count);
        $empty = collect(DB::select('CALL sp_department_statistics(?, ?)', ['2026-11-19', '2026-11-19']));
        foreach ($empty as $department) {
            $this->assertEquals(0, $department->capacity);
        }
    }
}
