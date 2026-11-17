<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoCredentialsTest extends TestCase
{
    private const PASSWORD = 'Synthetic-CLI-Password-2026';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->seed(DatabaseSeeder::class);
    }

    public function test_demo_password_reset_changes_hash_removes_old_sessions_and_preserves_business_data(): void
    {
        $user = User::where('username', 'demo_patient_1')->sole();
        $other = User::where('username', 'demo_patient_2')->sole();
        DB::table('sessions')->insert([
            ['id' => 'synthetic-old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'synthetic-other-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $before = [];
        foreach (['patients', 'departments', 'doctors', 'schedules', 'slots', 'appointments', 'payment_orders', 'refunds'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->artisan('mediqueue:demo-password', ['username' => 'DEMO_PATIENT_1'])
            ->expectsQuestion('输入新密码（至少12字符，最多72字节）', self::PASSWORD)
            ->expectsQuestion('再次输入新密码', self::PASSWORD)
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password_hash));
        $this->assertNotSame($user->password_hash, $user->fresh()->password_hash);
        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-old-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'synthetic-other-session']);
        $this->assertDatabaseCount('users', 6);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $audit = DB::table('audit_logs')->where('action', 'DEMO_PASSWORD_RESET')->sole();
        $this->assertSame($user->id, $audit->target_id);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit));
        $this->assertStringNotContainsString($user->fresh()->password_hash, json_encode($audit));
    }

    public function test_demo_password_reset_rejects_invalid_confirmation_and_preserves_hash(): void
    {
        $user = User::where('username', 'demo_patient_1')->sole();
        $this->artisan('mediqueue:demo-password', ['username' => $user->username])
            ->expectsQuestion('输入新密码（至少12字符，最多72字节）', self::PASSWORD)
            ->expectsQuestion('再次输入新密码', 'different-password')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertFailed();
        $this->assertSame($user->password_hash, $user->fresh()->password_hash);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_demo_password_command_rejects_non_demo_names_and_non_interactive_input(): void
    {
        $this->artisan('mediqueue:demo-password', ['username' => 'arbitrary_account'])->assertFailed();
        $this->artisan('mediqueue:demo-password', ['username' => 'demo_patient_1', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_both_credential_commands_are_disabled_in_production(): void
    {
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $this->app->instance('env', 'production');
        $this->artisan('mediqueue:demo-password', ['username' => 'demo_admin'])->assertFailed();
        $this->artisan('mediqueue:create-admin', ['username' => 'new_admin'])->assertFailed();
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_controlled_admin_creation_hashes_secret_and_creates_no_patient_or_doctor(): void
    {
        $this->artisan('mediqueue:create-admin', ['username' => '  New_Admin  '])
            ->expectsQuestion('输入管理员密码（至少12字符，最多72字节）', self::PASSWORD)
            ->expectsQuestion('再次输入管理员密码', self::PASSWORD)
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();
        $user = User::where('username', 'new_admin')->sole();
        $this->assertSame('ADMIN', $user->role);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        $this->assertDatabaseCount('patients', 3);
        $this->assertDatabaseCount('doctors', 2);
        $audit = DB::table('audit_logs')->where('action', 'ADMIN_CREATED')->sole();
        $this->assertSame($user->id, $audit->target_id);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit));
        $this->assertStringNotContainsString($user->password_hash, json_encode($audit));
    }

    public function test_admin_creation_rejects_duplicate_and_non_interactive_requests(): void
    {
        $user = User::where('username', 'demo_admin')->sole();
        $this->artisan('mediqueue:create-admin', ['username' => 'DEMO_ADMIN'])->assertFailed();
        $this->artisan('mediqueue:create-admin', ['username' => 'new_admin', '--no-interaction' => true])->assertFailed();
        $this->assertSame($user->password_hash, $user->fresh()->password_hash);
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
