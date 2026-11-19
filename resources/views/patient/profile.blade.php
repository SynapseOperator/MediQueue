@extends('layouts.app')
@section('title', '我的资料')
@section('content')
    <section class="panel mx-auto max-w-lg">
        <h1 class="page-title">我的资料</h1>
        <p class="mt-3 text-sm text-slate-600">登录账号：{{ $user->username }} · 患者</p>
        <form method="POST" action="{{ route('patient.profile.update') }}" class="mt-5 space-y-4">
            @csrf @method('PATCH')
            <label class="field">姓名<input name="full_name" value="{{ $oldInput['full_name'] ?? $patient->full_name }}" required maxlength="64" autocomplete="off"></label>
            <label class="field">联系方式<input name="phone" value="{{ $oldInput['phone'] ?? $patient->phone }}" required maxlength="32" autocomplete="off"></label>

            <button class="btn" type="submit">保存资料</button>
        </form>
    </section>
@endsection
