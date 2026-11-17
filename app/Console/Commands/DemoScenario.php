<?php

namespace App\Console\Commands;

use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class DemoScenario extends Command
{
    protected $signature = 'mediqueue:demo-scenario {prefix : Unique lowercase letters and digits, 6–20 characters}';

    protected $description = 'Create a separate synthetic local/demo scenario with hidden credentials';

    public function handle(): int
    {
        if (! app()->environment(['local', 'demo']) || ! $this->input->isInteractive()) {
            $this->error('仅允许在 local/demo 环境通过交互式隐藏输入创建演示场景。');

            return self::FAILURE;
        }
        $prefix = $this->argument('prefix');
        if (! preg_match('/\A[a-z0-9]{6,20}\z/', $prefix) || DB::table('users')->whereIn('username', [$prefix.'_admin', $prefix.'_doctor', $prefix.'_history'])->exists() || DB::table('departments')->where('code', strtoupper($prefix))->exists()) {
            $this->error('前缀无效或已经使用；未修改现有数据。');

            return self::FAILURE;
        }
        $adminPassword = $this->secret('输入新管理员密码', false);
        $adminConfirmation = $this->secret('再次输入新管理员密码', false);
        $doctorPassword = $this->secret('输入新医生密码', false);
        $doctorConfirmation = $this->secret('再次输入新医生密码', false);
        $validation = Validator::make([
            'admin' => $adminPassword, 'admin_confirmation' => $adminConfirmation,
            'doctor' => $doctorPassword, 'doctor_confirmation' => $doctorConfirmation,
        ], ['admin' => ['required', 'string', Password::defaults(), 'confirmed'], 'doctor' => ['required', 'string', Password::defaults(), 'confirmed']]);
        if ($validation->fails()) {
            $this->error('密码不符合要求或确认不一致；未创建数据。');

            return self::FAILURE;
        }

        $oldNow = CarbonImmutable::getTestNow();
        $oldMock = config('mediqueue.mock_payments_enabled');
        $pastStart = CarbonImmutable::now('Asia/Shanghai')->subDay()->startOfDay()->setTime(14, 0)->utc();
        $future = CarbonImmutable::now('Asia/Shanghai')->addDay()->startOfDay()->setTime(14, 0)->utc();
        $created = $pastStart->subHour();
        CarbonImmutable::setTestNow($created);
        config(['mediqueue.mock_payments_enabled' => true]);
        try {
            $result = DB::transaction(function () use ($prefix, $adminPassword, $doctorPassword, $created, $pastStart, $future): array {
                $stamp = ['created_at' => $created->format('Y-m-d H:i:s.v'), 'updated_at' => $created->format('Y-m-d H:i:s.v')];
                $admin = DB::table('users')->insertGetId(['username' => $prefix.'_admin', 'password_hash' => Hash::make($adminPassword), 'role' => 'ADMIN', 'status' => 'ACTIVE', ...$stamp]);
                $doctorUser = DB::table('users')->insertGetId(['username' => $prefix.'_doctor', 'password_hash' => Hash::make($doctorPassword), 'role' => 'DOCTOR', 'status' => 'ACTIVE', ...$stamp]);
                $patientUser = DB::table('users')->insertGetId(['username' => $prefix.'_history', 'password_hash' => Hash::make(bin2hex(random_bytes(24))), 'role' => 'PATIENT', 'status' => 'ACTIVE', ...$stamp]);
                DB::table('patients')->insert(['user_id' => $patientUser, 'full_name' => '演示历史患者', 'phone' => 'DEMO-HISTORY', ...$stamp]);
                $department = DB::table('departments')->insertGetId(['code' => strtoupper($prefix), 'name' => '演示科室 '.$prefix, 'introduction' => '仅用于演示的虚构科室。', 'status' => 'ACTIVE', ...$stamp]);
                $doctor = DB::table('doctors')->insertGetId(['user_id' => $doctorUser, 'department_id' => $department, 'full_name' => '演示林医生', 'title' => '演示医师', 'specialty' => '虚构康复咨询', 'introduction' => '仅用于课程演示，不提供真实诊疗。', 'status' => 'ACTIVE', ...$stamp]);
                $slots = [];
                $schedules = [];
                foreach ([$pastStart, $future] as $index => $start) {
                    $end = $start->addMinutes(30);
                    $schedules[$index] = DB::table('schedules')->insertGetId(['doctor_id' => $doctor, 'department_id' => $department, 'work_date' => $start->setTimezone('Asia/Shanghai')->toDateString(), 'session_code' => 'AFTERNOON', 'starts_at' => $start->format('Y-m-d H:i:s.v'), 'ends_at' => $end->format('Y-m-d H:i:s.v'), 'status' => 'OPEN', 'created_by' => $admin, ...$stamp]);
                    $slots[$index] = DB::table('slots')->insertGetId(['schedule_id' => $schedules[$index], 'starts_at' => $start->format('Y-m-d H:i:s.v'), 'ends_at' => $end->format('Y-m-d H:i:s.v'), 'capacity' => 4, 'occupied_count' => 0, 'fee_amount' => '20.00', 'status' => 'OPEN', ...$stamp]);
                }
                $appointment = app(AppointmentService::class)->create($patientUser, $slots[0], $prefix.'_historical');
                app(AppointmentLifecycleService::class)->pay($patientUser, $appointment);
                DB::table('audit_logs')->insert(['actor_type' => 'SYSTEM', 'operator_id' => null, 'action' => 'DEMO_SCENARIO_CREATED', 'target_type' => 'users', 'target_id' => $admin, 'after_state' => json_encode(['synthetic' => true, 'historical_clock' => true], JSON_THROW_ON_ERROR), 'created_at' => $created->format('Y-m-d H:i:s.v')]);

                return ['doctor' => $doctor, 'history' => $appointment, 'slot' => $slots[1], 'schedule' => $schedules[1]];
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('演示前缀已存在；未覆盖现有数据。');

            return self::FAILURE;
        } finally {
            CarbonImmutable::setTestNow($oldNow);
            config(['mediqueue.mock_payments_enabled' => $oldMock]);
        }
        $this->info("合成场景已创建：管理员 {$prefix}_admin，医生 {$prefix}_doctor，历史患者 {$prefix}_history。");
        $this->line("医生 {$result['doctor']}，历史预约 {$result['history']}，未来排班 {$result['schedule']}，未来时段 {$result['slot']}。密码未输出。");

        return self::SUCCESS;
    }
}
