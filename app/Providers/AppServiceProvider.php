<?php

namespace App\Providers;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as BladeView;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // These flat HTML forms only echo strings; rejected array input is never rendered.
        View::composer(['auth.*', 'patient.profile', 'admin.forms.*'], function (BladeView $view): void {
            $view->with('oldInput', array_filter(session()->getOldInput(), is_string(...)));
        });

        Password::defaults(fn () => Password::min(12)->rules([
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('密码最多72字节，且不能包含空字符。');
                }
            },
        ]));

        RateLimiter::for('login', function (Request $request): array {
            $input = $request->input('username');
            $username = is_string($input) ? strtolower(trim($input)) : '';

            return [
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $username.'|'.$request->ip())),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by('registration:'.$request->ip()));
    }
}
