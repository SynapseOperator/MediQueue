@extends('layouts.app')
@section('title', '患者注册')
@section('content')
    <section class="panel mx-auto max-w-lg">
        <h1 class="page-title">患者注册</h1>
        <p class="mt-3 text-sm leading-6 text-slate-600">注册后可预约挂号并查看就诊记录。</p>
        <form method="POST" action="{{ route('register.store') }}" class="mt-5 space-y-4">
            @csrf
            <label class="field">用户名<input name="username" value="{{ $oldInput['username'] ?? '' }}" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_]+" autocomplete="username"><span class="hint">3–64 位字母、数字或下划线，不区分大小写。</span></label>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="field">密码<input type="password" name="password" required minlength="12" autocomplete="new-password"><span class="hint">至少 12 位，请使用独立密码。</span></label>
                <label class="field">确认密码<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
            </div>
            <label class="field">姓名<input name="full_name" value="{{ $oldInput['full_name'] ?? '' }}" required maxlength="64" autocomplete="off" placeholder="请输入姓名"></label>
            <label class="field">联系方式<input name="phone" value="{{ $oldInput['phone'] ?? '' }}" required maxlength="32" autocomplete="off" placeholder="请输入联系电话"></label>
            <button class="btn w-full" type="submit">注册患者账号</button>
        </form>
        <p class="mt-6 text-sm text-slate-600">已有账号？<a class="text-link" href="{{ route('login') }}">返回登录</a></p>
    </section>
@endsection
