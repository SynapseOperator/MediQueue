@extends('layouts.app')
@section('title', '登录')
@section('content')
    <section class="panel mx-auto max-w-md">
        <p class="eyebrow">欢迎回来</p><h1 class="page-title mt-2">登录 MediQueue</h1>
        <p class="mt-3 text-sm text-slate-600">患者、医生和管理员使用各自账号登录。</p>
        <form method="POST" action="{{ route('login.store') }}" class="mt-7 space-y-5">
            @csrf
            <label class="field">用户名<input name="username" value="{{ $oldInput['username'] ?? '' }}" required maxlength="64" autocomplete="username" autofocus></label>
            <label class="field">密码<input type="password" name="password" required autocomplete="current-password"></label>
            <button class="btn w-full" type="submit">登录</button>
        </form>
        <p class="mt-6 text-sm text-slate-600">还没有患者账号？<a class="text-link" href="{{ route('register') }}">创建患者账号</a></p>
    </section>
@endsection
