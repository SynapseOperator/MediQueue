<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('routine_ddl')->unprepared(<<<'SQL'
            CREATE FUNCTION fn_intervals_overlap(a_start DATETIME(3), a_end DATETIME(3), b_start DATETIME(3), b_end DATETIME(3))
            RETURNS TINYINT DETERMINISTIC NO SQL SQL SECURITY INVOKER
            BEGIN
                IF a_start IS NULL OR a_end IS NULL OR b_start IS NULL OR b_end IS NULL OR a_start >= a_end OR b_start >= b_end THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Intervals must be non-null and have positive length';
                END IF;
                RETURN a_start < b_end AND b_start < a_end;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE SQL SECURITY INVOKER VIEW v_appointment_details AS
            SELECT a.id AS appointment_id, a.appointment_no, a.patient_id, p.user_id AS patient_user_id,
                   p.full_name AS patient_name, d.id AS doctor_id, d.full_name AS doctor_name,
                   dep.id AS department_id, dep.name AS department_name, s.id AS schedule_id, s.work_date,
                   sl.id AS slot_id, sl.starts_at, sl.ends_at, a.status AS appointment_status,
                   a.fee_amount, a.expires_at, a.created_at, po.id AS payment_order_id,
                   po.order_no, po.status AS payment_status, po.amount AS payment_amount,
                   r.id AS refund_id, r.refund_no, r.status AS refund_status, r.amount AS refund_amount
            FROM appointments a JOIN patients p ON p.id = a.patient_id
            JOIN slots sl ON sl.id = a.slot_id JOIN schedules s ON s.id = sl.schedule_id
            JOIN doctors d ON d.id = s.doctor_id JOIN departments dep ON dep.id = s.department_id
            JOIN payment_orders po ON po.appointment_id = a.id LEFT JOIN refunds r ON r.payment_order_id = po.id
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE SQL SECURITY INVOKER VIEW v_slot_occupancy_check AS
            SELECT sl.id AS slot_id, sl.schedule_id, sl.capacity, sl.occupied_count,
                   COUNT(a.id) AS actual_occupied_count,
                   CAST(sl.occupied_count AS SIGNED) - CAST(COUNT(a.id) AS SIGNED) AS difference
            FROM slots sl LEFT JOIN appointments a ON a.slot_id = sl.id
                 AND a.status IN ('PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED')
            GROUP BY sl.id, sl.schedule_id, sl.capacity, sl.occupied_count
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE PROCEDURE sp_department_statistics(IN date_from DATE, IN date_to DATE)
            READS SQL DATA SQL SECURITY INVOKER
            BEGIN
                IF date_from IS NULL OR date_to IS NULL OR date_from > date_to THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid date range';
                END IF;
                SELECT d.id AS department_id, d.code AS department_code, d.name AS department_name,
                       COALESCE(cap.capacity, 0) AS capacity, COALESCE(cap.occupied_count, 0) AS occupied_count,
                       COALESCE(apt.pending_payment, 0) AS pending_payment, COALESCE(apt.confirmed, 0) AS confirmed,
                       COALESCE(apt.completed, 0) AS completed, COALESCE(apt.cancelled, 0) AS cancelled,
                       COALESCE(apt.expired, 0) AS expired
                FROM departments d
                LEFT JOIN (
                    SELECT s.department_id, SUM(sl.capacity) AS capacity, SUM(sl.occupied_count) AS occupied_count
                    FROM schedules s JOIN slots sl ON sl.schedule_id = s.id
                    WHERE s.work_date BETWEEN date_from AND date_to GROUP BY s.department_id
                ) cap ON cap.department_id = d.id
                LEFT JOIN (
                    SELECT s.department_id, SUM(a.status = 'PENDING_PAYMENT') AS pending_payment,
                           SUM(a.status = 'CONFIRMED') AS confirmed, SUM(a.status = 'COMPLETED') AS completed,
                           SUM(a.status = 'CANCELLED') AS cancelled, SUM(a.status = 'EXPIRED') AS expired
                    FROM schedules s JOIN slots sl ON sl.schedule_id = s.id JOIN appointments a ON a.slot_id = sl.id
                    WHERE s.work_date BETWEEN date_from AND date_to GROUP BY s.department_id
                ) apt ON apt.department_id = d.id
                ORDER BY d.id;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_department_statistics');
        DB::connection('routine_ddl')->unprepared('DROP FUNCTION IF EXISTS fn_intervals_overlap');
        DB::unprepared('DROP VIEW IF EXISTS v_slot_occupancy_check');
        DB::unprepared('DROP VIEW IF EXISTS v_appointment_details');
    }
};
