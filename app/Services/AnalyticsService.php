<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /** Dates are validated at the HTTP boundary and represent inclusive hospital calendar dates. */
    public function report(string $from, string $to): array
    {
        $timezone = config('mediqueue.hospital_timezone');
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->format('Y-m-d H:i:s.v');
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s.v');
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');

        // Independent grains, one statement snapshot: never multiply slot capacity by appointment rows.
        $summary = DB::selectOne(<<<'SQL'
            WITH selected_slots AS (
                SELECT s.id, s.capacity, s.occupied_count, s.ends_at, h.status AS schedule_status
                FROM slots s JOIN schedules h ON h.id = s.schedule_id
                WHERE h.work_date BETWEEN ? AND ?
            ), cohort AS (
                SELECT COUNT(*) AS created_count, COALESCE(SUM(status = 'CANCELLED'), 0) AS cohort_cancelled
                FROM appointments WHERE created_at >= ? AND created_at < ?
            ), visits AS (
                SELECT COALESCE(SUM(a.status = 'CONFIRMED'), 0) AS confirmed_count,
                    COALESCE(SUM(a.status = 'COMPLETED'), 0) AS completed_count,
                    COALESCE(SUM(a.status IN ('CONFIRMED', 'COMPLETED')), 0) AS paid_valid_count,
                    COALESCE(SUM(s.schedule_status = 'SUSPENDED' AND a.status IN ('PENDING_PAYMENT', 'CONFIRMED')), 0) AS suspended_count,
                    COALESCE(SUM(a.status = 'CONFIRMED' AND s.ends_at <= ?), 0) AS unverified_count
                FROM appointments a JOIN selected_slots s ON s.id = a.slot_id
            ), capacity AS (
                SELECT COALESCE(SUM(capacity), 0) AS capacity, COALESCE(SUM(occupied_count), 0) AS occupied_count
                FROM selected_slots
            ), money AS (
                SELECT (SELECT COALESCE(SUM(amount), 0.00) FROM payment_orders WHERE paid_at >= ? AND paid_at < ?) AS simulated_paid,
                    (SELECT COALESCE(SUM(amount), 0.00) FROM refunds WHERE status = 'SUCCEEDED' AND succeeded_at >= ? AND succeeded_at < ?) AS simulated_refunded
            )
            SELECT cohort.*, visits.*, capacity.*, money.*, money.simulated_paid - money.simulated_refunded AS simulated_net,
                (SELECT COUNT(*) FROM refunds r JOIN payment_orders o ON o.id = r.payment_order_id
                 WHERE r.status IN ('PENDING', 'FAILED') AND o.status = 'REFUND_PENDING') AS pending_refunds,
                (SELECT COUNT(*) FROM v_slot_occupancy_check c JOIN selected_slots s ON s.id = c.slot_id WHERE c.difference <> 0) AS occupancy_mismatches
            FROM cohort CROSS JOIN visits CROSS JOIN capacity CROSS JOIN money
            SQL, [$from, $to, $start, $end, $now, $start, $end, $start, $end]);

        $departments = DB::select('CALL sp_department_statistics(?, ?)', [$from, $to]);
        // Hospital reporting uses contemporary Asia/Shanghai (UTC+08); numeric offset needs no MySQL timezone tables.
        $creationTrend = DB::table('appointments')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw("DATE(CONVERT_TZ(created_at, '+00:00', '+08:00')) AS creation_date, COUNT(*) AS appointment_count")
            ->groupBy('creation_date')->orderBy('creation_date')->get();
        $pendingRefunds = DB::table('v_appointment_details')->whereIn('refund_status', ['PENDING', 'FAILED'])
            ->where('payment_status', 'REFUND_PENDING')->orderBy('refund_id')->limit(5)
            ->get(['appointment_id', 'appointment_no', 'refund_status', 'refund_amount']);
        $suspended = DB::table('v_appointment_details as a')->join('schedules as h', 'h.id', '=', 'a.schedule_id')
            ->whereBetween('a.work_date', [$from, $to])->where('h.status', 'SUSPENDED')
            ->whereIn('a.appointment_status', ['PENDING_PAYMENT', 'CONFIRMED'])->orderBy('a.starts_at')->limit(5)
            ->get(['a.appointment_id', 'a.appointment_no', 'a.department_name', 'a.appointment_status']);
        $unverified = DB::table('v_appointment_details')->whereBetween('work_date', [$from, $to])
            ->where('appointment_status', 'CONFIRMED')->where('ends_at', '<=', $now)->orderBy('ends_at')->limit(5)
            ->get(['appointment_id', 'appointment_no', 'department_name', 'ends_at']);

        return compact('from', 'to', 'summary', 'departments', 'creationTrend', 'pendingRefunds', 'suspended', 'unverified');
    }
}
