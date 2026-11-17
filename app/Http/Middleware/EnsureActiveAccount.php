<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->status !== 'ACTIVE') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => '账号已停用，请联系管理员。'], 403)
                : redirect()->route('login')->withErrors(['username' => '账号已停用，请联系管理员。']);
        }

        return $next($request);
    }
}
