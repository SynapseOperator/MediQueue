<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoScenarioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->seed();
        $this->app->instance('env', 'local');
    }

    public function test_scenario_adds_isolated_synthetic_data_and_rejects_duplicate_without_changes(): void
    {
        $adminPassword = 'Synthetic-Admin-Password-2026';
        $doctorPassword = 'Synthetic-Doctor-Password-2026';
        $beforeIds = DB::table('users')->pluck('id');
        $beforeUsers = DB::table('users')->whereIn('id', $beforeIds)->orderBy('id')->get()->toJson();
        $this->artisan('mediqueue:demo-scenario', ['prefix' => 'fixture123'])
            ->expectsQuestion('输入新管理员密码', $adminPassword)->expectsQuestion('再次输入新管理员密码', $adminPassword)
            ->expectsQuestion('输入新医生密码', $doctorPassword)->expectsQuestion('再次输入新医生密码', $doctorPassword)
            ->doesntExpectOutputToContain($adminPassword)->doesntExpectOutputToContain($doctorPassword)->assertSuccessful();
        $this->assertSame($beforeUsers, DB::table('users')->whereIn('id', $beforeIds)->orderBy('id')->get()->toJson());
        $admin = DB::table('users')->where('username', 'fixture123_admin')->sole();
        $doctor = DB::table('users')->where('username', 'fixture123_doctor')->sole();
        $this->assertTrue(Hash::check($adminPassword, $admin->password_hash));
        $this->assertTrue(Hash::check($doctorPassword, $doctor->password_hash));
        $patientId = DB::table('patients')->join('users', 'users.id', '=', 'patients.user_id')->where('users.username', 'fixture123_history')->value('patients.id');
        $appointment = DB::table('appointments')->where('patient_id', $patientId)->sole();
        $this->assertSame('CONFIRMED', $appointment->status);
        $this->assertDatabaseHas('payment_orders', ['appointment_id' => $appointment->id, 'status' => 'PAID']);
        $this->assertLessThan(CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'), DB::table('slots')->where('id', $appointment->slot_id)->value('ends_at'));
        $snapshot = DB::table('users')->orderBy('id')->get()->toJson();
        $this->artisan('mediqueue:demo-scenario', ['prefix' => 'fixture123'])->assertFailed();
        $this->assertSame($snapshot, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_scenario_rejects_production_noninteractive_and_bad_prefix(): void
    {
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $this->artisan('mediqueue:demo-scenario', ['prefix' => 'invalid-prefix'])->assertFailed();
        $this->artisan('mediqueue:demo-scenario', ['prefix' => 'fixture456', '--no-interaction' => true])->assertFailed();
        $this->app->instance('env', 'production');
        $this->artisan('mediqueue:demo-scenario', ['prefix' => 'fixture456'])->assertFailed();
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('appointments', 0);
    }
}
