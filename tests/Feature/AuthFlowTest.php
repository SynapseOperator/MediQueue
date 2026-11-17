<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    private const PASSWORD = 'Synthetic-Password-2026';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->seed(DatabaseSeeder::class);
        $this->withoutVite();

        // Run the real CSRF middleware: its default unit-test bypass is disabled.
        // The already verified MySQL connection remains mediqueue_test.
        $this->app->instance('env', 'local');
        foreach (['PATIENT', 'DOCTOR', 'ADMIN'] as $role) {
            Route::middleware(['web', 'auth', 'role:'.$role])->get('/_auth-test/'.$role, fn () => response('allowed'));
        }
    }

    public function test_registration_creates_one_patient_atomically_and_regenerates_session(): void
    {
        $this->withSession(['_token' => 'registration-token']);
        $oldSession = session()->getId();
        $this->form('POST', '/register', $this->registration(['username' => '  New_Patient  ']))
            ->assertRedirect(route('patient.profile.edit'));

        $user = User::where('username', 'new_patient')->sole();
        $this->assertSame('PATIENT', $user->role);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        $this->assertDatabaseHas('patients', ['user_id' => $user->id, 'full_name' => '合成测试患者', 'phone' => 'synthetic-contact']);
        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('patients', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PATIENT_REGISTERED', 'operator_id' => $user->id]);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertDatabaseMissing('sessions', ['id' => $oldSession]);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $user->id]);
        $this->assertTrue(session()->has('password_hash_web'));
        $this->browser()->get('/patient/profile')->assertOk()->assertSee('合成测试患者');
    }

    public function test_case_insensitive_duplicate_registration_is_rejected_without_partial_data(): void
    {
        $this->form('POST', '/register', $this->registration(['username' => 'DEMO_PATIENT_1']))
            ->assertSessionHasErrors('username');
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('patients', 3);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertGuest();
    }

    #[DataProvider('invalidRegistration')]
    public function test_registration_rejects_invalid_fields_and_privileged_identity(array $changes, string $error): void
    {
        $this->form('POST', '/register', $this->registration($changes))->assertSessionHasErrors($error);
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('patients', 3);
        $this->assertGuest();
    }

    public static function invalidRegistration(): array
    {
        return [
            'admin role' => [['role' => 'ADMIN'], 'role'],
            'doctor role' => [['role' => 'DOCTOR'], 'role'],
            'disabled status' => [['status' => 'DISABLED'], 'status'],
            'user identity' => [['user_id' => 99], 'user_id'],
            'patient identity' => [['patient_id' => 99], 'patient_id'],
            'accented username' => [['username' => 'patiént'], 'username'],
            'short username' => [['username' => 'ab'], 'username'],
            'missing name' => [['full_name' => ''], 'full_name'],
            'missing contact' => [['phone' => ''], 'phone'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'mismatched password' => [['password_confirmation' => 'different-password'], 'password'],
            'bcrypt byte limit' => [['password' => str_repeat('测', 25), 'password_confirmation' => str_repeat('测', 25)], 'password'],
            'null byte password' => [['password' => "Synthetic\0Password", 'password_confirmation' => "Synthetic\0Password"], 'password'],
            'array password' => [['password' => ['bad'], 'password_confirmation' => ['bad']], 'password'],
        ];
    }

    public function test_registration_rolls_back_user_and_patient_when_audit_write_fails(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `audit_logs`')) {
                throw new RuntimeException('Injected audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->form('POST', '/register', $this->registration());
            $this->fail('Expected the injected transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('patients', 3);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertGuest();
    }

    #[DataProvider('roles')]
    public function test_all_roles_login_with_password_hash_and_cannot_cross_role_boundary(string $username, string $role): void
    {
        $user = $this->passwordFor($username);
        $this->withSession(['_token' => 'login-token']);
        $oldSession = session()->getId();
        $this->form('POST', '/login', ['username' => strtoupper($username), 'password' => self::PASSWORD])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertDatabaseMissing('sessions', ['id' => $oldSession]);
        $this->browser()->get('/_auth-test/'.$role)->assertOk();
        foreach (array_diff(['PATIENT', 'DOCTOR', 'ADMIN'], [$role]) as $other) {
            $this->browser()->get('/_auth-test/'.$other)->assertForbidden();
        }
    }

    public static function roles(): array
    {
        return [['demo_patient_1', 'PATIENT'], ['demo_doctor_1', 'DOCTOR'], ['demo_admin', 'ADMIN']];
    }

    public function test_bad_password_unknown_and_disabled_account_have_same_public_error(): void
    {
        $user = $this->passwordFor('demo_patient_1');
        $user->update(['status' => 'DISABLED']);
        foreach (['demo_patient_1', 'demo_patient_2', 'missing_account'] as $username) {
            $this->form('POST', '/login', ['username' => $username, 'password' => self::PASSWORD])
                ->assertSessionHasErrors(['username' => '账号或密码错误，或账号已停用。']);
            $this->assertGuest();
        }
    }

    public function test_disabled_account_loses_existing_session_on_next_request(): void
    {
        $user = $this->loginPatient();
        $oldSession = session()->getId();
        DB::table('users')->where('id', $user->id)->update(['status' => 'DISABLED']);
        $this->browser()->get('/patient/profile')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['id' => $oldSession]);
    }

    public function test_password_change_invalidates_session_even_before_first_protected_request(): void
    {
        $user = $this->loginPatient();
        DB::table('users')->where('id', $user->id)->update(['password_hash' => Hash::make('Changed-Synthetic-Password')]);
        $this->browser()->get('/patient/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_and_regenerates_csrf_token(): void
    {
        $this->loginPatient();
        $oldSession = session()->getId();
        $oldToken = session()->token();
        $this->form('POST', '/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertDatabaseMissing('sessions', ['id' => $oldSession]);
        $this->browser()->get('/patient/profile')->assertRedirect('/login');
    }

    public function test_real_csrf_middleware_rejects_unauthenticated_writes_without_token(): void
    {
        $this->post('/register', $this->registration())->assertStatus(419);
        $this->post('/login', ['username' => 'demo_patient_1', 'password' => self::PASSWORD])->assertStatus(419);
        $this->assertDatabaseCount('users', 6);
        $this->assertGuest();
    }

    public function test_real_csrf_middleware_rejects_authenticated_writes_without_token(): void
    {
        $user = $this->loginPatient();
        $before = DB::table('patients')->where('user_id', $user->id)->sole();
        $this->browser()->patch('/patient/profile', ['full_name' => '不应保存', 'phone' => 'invalid'])->assertStatus(419);
        $this->browser()->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('patients', ['id' => $before->id, 'full_name' => $before->full_name, 'phone' => $before->phone]);
    }

    public function test_login_attempt_limit_combines_case_variants_of_same_account(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->form('POST', '/login', ['username' => $attempt % 2 ? 'demo_patient_1' : 'DEMO_PATIENT_1', 'password' => self::PASSWORD])
                ->assertSessionHasErrors('username');
        }
        $this->form('POST', '/login', ['username' => 'demo_patient_1', 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_registration_attempt_limit_is_enforced(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->form('POST', '/register', $this->registration(['username' => 'ab']))->assertSessionHasErrors('username');
        }
        $this->form('POST', '/register', $this->registration())->assertStatus(429);
        $this->assertDatabaseCount('users', 6);
    }

    public function test_patient_profile_always_reads_and_updates_current_session_owner(): void
    {
        $user = $this->loginPatient();
        $mine = DB::table('patients')->where('user_id', $user->id)->sole();
        $other = DB::table('patients')->where('user_id', '!=', $user->id)->first();
        $this->browser()->get('/patient/profile?user_id='.$other->user_id.'&patient_id='.$other->id)
            ->assertOk()->assertViewHas('patient', fn ($patient) => $patient->id === $mine->id);
        $this->form('PATCH', '/patient/profile', ['full_name' => '合成更新姓名', 'phone' => 'synthetic-updated'])
            ->assertRedirect(route('patient.profile.edit'));
        $this->assertDatabaseHas('patients', ['id' => $mine->id, 'full_name' => '合成更新姓名']);
        $this->assertDatabaseHas('patients', ['id' => $other->id, 'full_name' => $other->full_name, 'phone' => $other->phone]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PATIENT_PROFILE_UPDATED', 'target_id' => $mine->id, 'operator_id' => $user->id]);
        $audit = DB::table('audit_logs')->where('action', 'PATIENT_PROFILE_UPDATED')->sole();
        $this->assertStringNotContainsString('synthetic-updated', $audit->after_state);
        $this->assertStringNotContainsString('合成更新姓名', $audit->after_state);

        $this->form('PATCH', '/patient/profile', ['full_name' => '不应保存', 'phone' => 'invalid', 'user_id' => $other->user_id])
            ->assertSessionHasErrors('user_id');
        // Empty prohibited fields must not become writable identity columns.
        $this->form('PATCH', '/patient/profile', ['full_name' => '合成最终姓名', 'phone' => 'synthetic-final', 'user_id' => '', 'id' => ''])
            ->assertRedirect(route('patient.profile.edit'));
        $this->assertDatabaseHas('patients', ['id' => $mine->id, 'user_id' => $user->id, 'full_name' => '合成最终姓名']);
    }

    public function test_non_patient_and_guest_cannot_edit_patient_profile(): void
    {
        $this->get('/patient/profile')->assertRedirect('/login');
        $this->passwordFor('demo_doctor_1');
        $this->form('POST', '/login', ['username' => 'demo_doctor_1', 'password' => self::PASSWORD])->assertRedirect('/patient/profile');
        $this->browser()->get('/patient/profile')->assertForbidden();
        $this->form('PATCH', '/patient/profile', ['full_name' => '不应保存', 'phone' => 'invalid'])->assertForbidden();
    }

    private function registration(array $changes = []): array
    {
        return array_replace([
            'username' => 'new_patient', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'full_name' => '合成测试患者', 'phone' => 'synthetic-contact',
        ], $changes);
    }

    private function passwordFor(string $username): User
    {
        $user = User::where('username', $username)->sole();
        $user->update(['password_hash' => Hash::make(self::PASSWORD)]);

        return $user;
    }

    private function loginPatient(): User
    {
        $user = $this->passwordFor('demo_patient_1');
        $this->form('POST', '/login', ['username' => $user->username, 'password' => self::PASSWORD])->assertRedirect('/');

        return $user;
    }

    private function browser(): static
    {
        $sessionId = session()->getId();
        if (session()->isStarted()) {
            session()->save();
        }
        // Discard both in-memory stores so authentication must reload the actual
        // encrypted database session through the browser's session cookie.
        session()->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->make('redirect')->setSession($this->app->make('session.store'));
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');

        return $this->withCookie(config('session.cookie'), $sessionId);
    }

    private function form(string $method, string $uri, array $data = []): TestResponse
    {
        $this->withSession(['_token' => session()->token() ?? 'synthetic-csrf-token']);
        $token = session()->token();
        $this->browser();

        return $this->call($method, $uri, [...$data, '_token' => $token], $this->prepareCookiesForRequest());
    }
}
