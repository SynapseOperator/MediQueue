<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth.login');
    }

    public function registerForm(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => [...User::usernameRules(), Rule::unique('users', 'username')],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'full_name' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'role' => ['prohibited'], 'status' => ['prohibited'],
            'user_id' => ['prohibited'], 'patient_id' => ['prohibited'],
        ]);

        try {
            $user = DB::transaction(function () use ($data): User {
                $user = User::create([
                    'username' => User::normalizeUsername($data['username']),
                    'password_hash' => Hash::make($data['password']),
                    'role' => 'PATIENT', 'status' => 'ACTIVE',
                ]);
                $now = now('UTC')->format('Y-m-d H:i:s.v');
                DB::table('patients')->insert([
                    'user_id' => $user->id, 'full_name' => $data['full_name'], 'phone' => $data['phone'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('audit_logs')->insert([
                    'actor_type' => 'USER', 'operator_id' => $user->id,
                    'action' => 'PATIENT_REGISTERED', 'target_type' => 'users', 'target_id' => $user->id,
                    'after_state' => json_encode(['role' => 'PATIENT', 'status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => '该账号已被使用，请选择其他账号。']);
        }

        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard()->hashPasswordForCookie($user->getAuthPassword()));

        return redirect()->route('patient.profile.edit');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => User::usernameRules(),
            'password' => ['required', 'string', Password::defaults()],
            'remember' => ['prohibited'],
        ]);

        if (! Auth::attempt([
            'username' => User::normalizeUsername($data['username']),
            'password' => $data['password'], 'status' => 'ACTIVE',
        ], false)) {
            throw ValidationException::withMessages(['username' => '账号或密码错误，或账号已停用。']);
        }

        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard()->hashPasswordForCookie($request->user()->getAuthPassword()));

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
