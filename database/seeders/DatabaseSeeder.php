<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'demo'])) {
            throw new RuntimeException('Demo seed data is allowed only in local, testing, or demo environments.');
        }

        DB::transaction(function (): void {
            foreach (['users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds', 'audit_logs'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Demo seed requires all core business tables to be empty.');
                }
            }

            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');
            $timestamps = ['created_at' => $now, 'updated_at' => $now];
            $adminId = DB::table('users')->insertGetId([
                'username' => 'demo_admin', 'password_hash' => Hash::make(bin2hex(random_bytes(32))),
                'role' => 'ADMIN', 'status' => 'ACTIVE', ...$timestamps,
            ]);

            foreach ([
                ['code' => 'DEMO_GENERAL', 'department' => '演示全科', 'name' => '演示医生甲', 'username' => 'demo_doctor_1', 'fee' => '12.34'],
                ['code' => 'DEMO_SPECIALTY', 'department' => '演示专科', 'name' => '演示医生乙', 'username' => 'demo_doctor_2', 'fee' => '30.00'],
            ] as $doctor) {
                $departmentId = DB::table('departments')->insertGetId([
                    'code' => $doctor['code'], 'name' => $doctor['department'], 'status' => 'ACTIVE', ...$timestamps,
                ]);
                $userId = DB::table('users')->insertGetId([
                    'username' => $doctor['username'], 'password_hash' => Hash::make(bin2hex(random_bytes(32))),
                    'role' => 'DOCTOR', 'status' => 'ACTIVE', ...$timestamps,
                ]);
                $doctorId = DB::table('doctors')->insertGetId([
                    'user_id' => $userId, 'department_id' => $departmentId, 'full_name' => $doctor['name'],
                    'title' => '演示医师', 'status' => 'ACTIVE', ...$timestamps,
                ]);
                $start = CarbonImmutable::now('Asia/Shanghai')->addDay()->startOfDay()->setTime(9, 0);
                $scheduleId = DB::table('schedules')->insertGetId([
                    'doctor_id' => $doctorId, 'department_id' => $departmentId, 'work_date' => $start->toDateString(),
                    'session_code' => 'MORNING', 'starts_at' => $start->utc()->format('Y-m-d H:i:s.v'),
                    'ends_at' => $start->addHour()->utc()->format('Y-m-d H:i:s.v'),
                    'status' => 'OPEN', 'created_by' => $adminId, ...$timestamps,
                ]);
                foreach ([3, 2] as $index => $capacity) {
                    DB::table('slots')->insert([
                        'schedule_id' => $scheduleId,
                        'starts_at' => $start->addMinutes($index * 30)->utc()->format('Y-m-d H:i:s.v'),
                        'ends_at' => $start->addMinutes(($index + 1) * 30)->utc()->format('Y-m-d H:i:s.v'),
                        'capacity' => $capacity, 'occupied_count' => 0, 'fee_amount' => $doctor['fee'],
                        'status' => 'OPEN', ...$timestamps,
                    ]);
                }
            }

            foreach (['甲', '乙', '丙'] as $index => $name) {
                $number = $index + 1;
                $userId = DB::table('users')->insertGetId([
                    'username' => 'demo_patient_'.$number, 'password_hash' => Hash::make(bin2hex(random_bytes(32))),
                    'role' => 'PATIENT', 'status' => 'ACTIVE', ...$timestamps,
                ]);
                DB::table('patients')->insert([
                    'user_id' => $userId, 'full_name' => '演示患者'.$name,
                    'phone' => sprintf('DEMO-PATIENT-%03d', $number), ...$timestamps,
                ]);
            }
        });
    }
}
