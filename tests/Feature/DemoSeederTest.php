<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack();
            CarbonImmutable::setTestNow();
        });
    }

    public function test_demo_seed_creates_related_synthetic_fixtures_with_utc_times(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16T20:30:00Z'));
        $this->seed(DatabaseSeeder::class);

        foreach (['users' => 6, 'patients' => 3, 'departments' => 2, 'doctors' => 2, 'schedules' => 2, 'slots' => 4, 'appointments' => 0, 'payment_orders' => 0, 'refunds' => 0, 'audit_logs' => 0] as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }

        foreach (DB::table('users')->get() as $user) {
            $this->assertStringStartsWith('demo_', $user->username);
            $this->assertNotSame('unknown', password_get_info($user->password_hash)['algoName']);
            $this->assertSame('ACTIVE', $user->status);
        }
        $admin = DB::table('users')->where('username', 'demo_admin')->first();
        $this->assertSame('ADMIN', $admin->role);
        $patients = DB::table('patients')->join('users', 'patients.user_id', '=', 'users.id')->select('patients.*', 'users.role')->orderBy('patients.id')->get();
        foreach ($patients as $index => $patient) {
            $this->assertSame('PATIENT', $patient->role);
            $this->assertSame(['陈嘉禾', '徐明远', '宋安宁'][$index], $patient->full_name);
            $this->assertSame(sprintf('010-0000%04d', $index + 1), $patient->phone);
        }
        $doctors = DB::table('doctors')->join('users', 'doctors.user_id', '=', 'users.id')->join('departments', 'doctors.department_id', '=', 'departments.id')->select('doctors.*', 'users.role', 'departments.name as department_name')->orderBy('doctors.id')->get();
        foreach ($doctors as $doctorIndex => $doctor) {
            $this->assertSame('DOCTOR', $doctor->role);
            $this->assertSame(['李建国', '周晓岚'][$doctorIndex], $doctor->full_name);
            $this->assertSame(['心血管内科', '儿科'][$doctorIndex], $doctor->department_name);
            $schedule = DB::table('schedules')->where('doctor_id', $doctor->id)->sole();
            $this->assertSame($doctor->department_id, $schedule->department_id);
            $this->assertSame($admin->id, $schedule->created_by);
            $this->assertSame('2026-11-18', $schedule->work_date);
            $this->assertSame('2026-11-18 01:00:00.000', $schedule->starts_at);
            $this->assertSame('2026-11-18 02:00:00.000', $schedule->ends_at);
            $slots = DB::table('slots')->where('schedule_id', $schedule->id)->orderBy('starts_at')->get();
            foreach ($slots as $index => $slot) {
                $this->assertSame(['2026-11-18 01:00:00.000', '2026-11-18 01:30:00.000'][$index], $slot->starts_at);
                $this->assertSame(['2026-11-18 01:30:00.000', '2026-11-18 02:00:00.000'][$index], $slot->ends_at);
                $this->assertSame([3, 2][$index], $slot->capacity);
                $this->assertSame(0, $slot->occupied_count);
                $this->assertSame($doctor->full_name === '李建国' ? '12.34' : '30.00', $slot->fee_amount);
            }
        }
    }

    public function test_repeated_seed_rejects_nonempty_business_tables_without_changing_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $slot = DB::table('slots')->first();
        DB::table('slots')->where('id', $slot->id)->update(['occupied_count' => 1]);
        $users = DB::table('users')->orderBy('id')->get()->toArray();
        $slots = DB::table('slots')->orderBy('id')->get()->toArray();

        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Expected a nonempty database to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('Demo seed requires all core business tables to be empty.', $e->getMessage());
        }
        $this->assertEquals($users, DB::table('users')->orderBy('id')->get()->toArray());
        $this->assertEquals($slots, DB::table('slots')->orderBy('id')->get()->toArray());
    }

    public function test_production_rejects_even_forced_seed(): void
    {
        app()->detectEnvironment(fn () => 'production');
        try {
            Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
            $this->fail('Expected production to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('Demo seed data is allowed only in local, testing, or demo environments.', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('slots')->count());
    }

    public function test_failure_during_seed_rolls_back_all_fixtures(): void
    {
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower($query->sql), 'insert into `slots`')) {
                $injected = true;
                throw new RuntimeException('Injected seed failure.');
            }
        });
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Expected the injected failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected seed failure.', $e->getMessage());
        }
        $this->assertTrue($injected);
        foreach (['users', 'patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
