<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * 回归测试使用的固定基线夹具。号源、容量和挂号费被大量测试引用，修改前请同步测试。
     * 全部人员与资料均为虚构。
     */
    private const BASELINE = [
        [
            'code' => 'CARD', 'department' => '心血管内科', 'username' => 'demo_doctor_1', 'fee' => '12.34', 'days' => 1, 'capacities' => [3, 2],
            'department_intro' => '诊治高血压、冠心病、心律失常和心力衰竭等心血管疾病，提供长期随访和用药调整。',
            'name' => '李建国', 'title' => '主任医师', 'specialty' => '高血压、冠心病、心力衰竭的长期管理',
            'intro' => '从事心血管内科临床工作二十余年，擅长难治性高血压的用药调整和冠心病二级预防。',
        ],
        [
            'code' => 'PED', 'department' => '儿科', 'username' => 'demo_doctor_2', 'fee' => '30.00', 'days' => 1, 'capacities' => [3, 2],
            'department_intro' => '面向 14 岁以下儿童，诊治常见呼吸道和消化道疾病，并提供生长发育评估与预防保健咨询。',
            'name' => '周晓岚', 'title' => '副主任医师', 'specialty' => '儿童呼吸系统疾病、儿童保健',
            'intro' => '长期从事儿童呼吸系统疾病诊治，关注儿童哮喘的规范化管理和家庭护理指导。',
        ],
    ];

    /**
     * 本地与演示环境追加的完整医院目录；测试环境只使用基线，保持夹具数量稳定。
     * 全部人员与资料均为虚构。
     */
    private const DIRECTORY = [
        [
            'code' => 'RESP', 'department' => '呼吸内科', 'username' => 'demo_doctor_3', 'fee' => '20.00', 'days' => 2, 'capacities' => [4, 4],
            'department_intro' => '诊治慢性咳嗽、哮喘、慢性阻塞性肺疾病和肺部感染，提供肺功能检查结果的复诊解读。',
            'name' => '王思宁', 'title' => '主治医师', 'specialty' => '慢性咳嗽、支气管哮喘',
            'intro' => '擅长慢性咳嗽的病因排查和哮喘吸入治疗指导。',
        ],
        [
            'code' => 'GI', 'department' => '消化内科', 'username' => 'demo_doctor_4', 'fee' => '30.00', 'days' => 2, 'capacities' => [3, 3],
            'department_intro' => '诊治胃炎、消化性溃疡、肠易激综合征和肝胆疾病，可在门诊完成胃肠镜检查前评估。',
            'name' => '赵立言', 'title' => '副主任医师', 'specialty' => '慢性胃炎、功能性胃肠病',
            'intro' => '从事消化内科工作十五年，擅长功能性胃肠病的综合治疗。',
        ],
        [
            'code' => 'ORTH', 'department' => '骨科', 'username' => 'demo_doctor_5', 'fee' => '40.00', 'days' => 3, 'capacities' => [3, 2],
            'department_intro' => '诊治颈肩腰腿痛、骨关节炎和运动损伤，提供骨折术后复查与康复指导。',
            'name' => '孙慕白', 'title' => '主任医师', 'specialty' => '膝关节疾病、运动损伤',
            'intro' => '擅长膝关节骨关节炎的阶梯治疗和运动损伤后的功能评估。',
        ],
        [
            'code' => 'DERM', 'department' => '皮肤科', 'username' => 'demo_doctor_6', 'fee' => '20.00', 'days' => 3, 'capacities' => [4, 4],
            'department_intro' => '诊治湿疹、痤疮、荨麻疹和真菌感染等常见皮肤病。',
            'name' => '陆清禾', 'title' => '主治医师', 'specialty' => '湿疹、痤疮',
            'intro' => '关注慢性湿疹的长期管理和青少年痤疮的规范治疗。',
        ],
        [
            'code' => 'OPH', 'department' => '眼科', 'username' => 'demo_doctor_7', 'fee' => '30.00', 'days' => 4, 'capacities' => [3, 3],
            'department_intro' => '提供视力检查、干眼、白内障术前评估和青少年近视防控门诊。',
            'name' => '何以安', 'title' => '副主任医师', 'specialty' => '干眼、青少年近视防控',
            'intro' => '擅长干眼的综合治疗和青少年近视的随访管理。',
        ],
    ];

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

            $doctors = app()->environment('testing') ? self::BASELINE : [...self::BASELINE, ...self::DIRECTORY];
            foreach ($doctors as $doctor) {
                $departmentId = DB::table('departments')->insertGetId([
                    'code' => $doctor['code'], 'name' => $doctor['department'], 'introduction' => $doctor['department_intro'],
                    'status' => 'ACTIVE', ...$timestamps,
                ]);
                $userId = DB::table('users')->insertGetId([
                    'username' => $doctor['username'], 'password_hash' => Hash::make(bin2hex(random_bytes(32))),
                    'role' => 'DOCTOR', 'status' => 'ACTIVE', ...$timestamps,
                ]);
                $doctorId = DB::table('doctors')->insertGetId([
                    'user_id' => $userId, 'department_id' => $departmentId, 'full_name' => $doctor['name'],
                    'title' => $doctor['title'], 'specialty' => $doctor['specialty'], 'introduction' => $doctor['intro'],
                    'status' => 'ACTIVE', ...$timestamps,
                ]);
                $start = CarbonImmutable::now('Asia/Shanghai')->addDays($doctor['days'])->startOfDay()->setTime(9, 0);
                $scheduleId = DB::table('schedules')->insertGetId([
                    'doctor_id' => $doctorId, 'department_id' => $departmentId, 'work_date' => $start->toDateString(),
                    'session_code' => 'MORNING', 'starts_at' => $start->utc()->format('Y-m-d H:i:s.v'),
                    'ends_at' => $start->addHour()->utc()->format('Y-m-d H:i:s.v'),
                    'status' => 'OPEN', 'created_by' => $adminId, ...$timestamps,
                ]);
                foreach ($doctor['capacities'] as $index => $capacity) {
                    DB::table('slots')->insert([
                        'schedule_id' => $scheduleId,
                        'starts_at' => $start->addMinutes($index * 30)->utc()->format('Y-m-d H:i:s.v'),
                        'ends_at' => $start->addMinutes(($index + 1) * 30)->utc()->format('Y-m-d H:i:s.v'),
                        'capacity' => $capacity, 'occupied_count' => 0, 'fee_amount' => $doctor['fee'],
                        'status' => 'OPEN', ...$timestamps,
                    ]);
                }
            }

            foreach (['陈嘉禾', '徐明远', '宋安宁'] as $index => $name) {
                $number = $index + 1;
                $userId = DB::table('users')->insertGetId([
                    'username' => 'demo_patient_'.$number, 'password_hash' => Hash::make(bin2hex(random_bytes(32))),
                    'role' => 'PATIENT', 'status' => 'ACTIVE', ...$timestamps,
                ]);
                DB::table('patients')->insert([
                    'user_id' => $userId, 'full_name' => $name,
                    'phone' => sprintf('010-0000%04d', $number), ...$timestamps,
                ]);
            }
        });
    }
}
