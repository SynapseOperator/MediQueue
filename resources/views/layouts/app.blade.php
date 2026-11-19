<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f6e84">
    <title>@yield('title', '医院预约挂号') · MediQueue</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $role = auth()->user()?->role;
    $adminArea = $role === 'ADMIN' && request()->routeIs('admin.*');
    $links = $adminArea ? [
        ['admin.dashboard', 'admin.dashboard', '管理看板'],
        ['admin.resources', 'admin.resources', '资源管理'],
        ['admin.appointments.index', 'admin.appointments.*', '预约处理'],
        ['admin.analytics', 'admin.analytics', '统计'],
        ['admin.audit', 'admin.audit', '审计'],
    ] : [
        ['departments.index', 'departments.*', '科室'],
        ['doctors.index', 'doctors.*', '找医生'],
    ];
    if ($role === 'PATIENT') {
        $links[] = ['appointments.index', 'appointments.*', '我的预约'];
        $links[] = ['patient.profile.edit', 'patient.profile.*', '我的资料'];
    } elseif ($role === 'DOCTOR') {
        $links[] = ['doctor.workbench', 'doctor.*', '医生工作台'];
    } elseif ($role === 'ADMIN' && ! $adminArea) {
        $links[] = ['admin.dashboard', 'admin.*', '管理看板'];
    }
@endphp
<body>
<a class="skip-link" href="#main-content">跳到主要内容</a>
<div class="demo-banner"><div class="site-width">演示环境 · 支付为模拟，不产生真实扣款；所有人员和数据均为虚构</div></div>
<header class="site-header">
    <div class="site-width header-row">
        <a class="site-brand" href="{{ route('home') }}" aria-label="MediQueue 首页">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 4v16M4 12h16" stroke="currentColor" stroke-width="3"/></svg>
            </span>
            <span>MediQueue</span>
        </a>
        @unless($adminArea)
            <nav class="top-nav" aria-label="主导航">@include('layouts.nav-links')</nav>
        @endunless
        <div class="account-nav">@include('layouts.account')</div>
        <details class="nav-mobile">
            <summary><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2"/></svg> 菜单</summary>
            <nav class="nav-drawer" aria-label="移动端导航">
                @include('layouts.nav-links')
                <div class="drawer-account">@include('layouts.account')</div>
            </nav>
        </details>
    </div>
</header>
<div class="site-width content-shell {{ $adminArea ? 'admin-shell' : '' }}">
    @if($adminArea)
        <aside class="admin-sidebar"><nav aria-label="管理导航">@include('layouts.nav-links')</nav></aside>
    @endif
    <main id="main-content">
        @if(session('status'))
            @include('layouts.status-message', ['message' => session('status')])
        @endif
        @if($errors->any())
            <div class="alert-error" role="alert"><p>请检查以下内容</p><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </main>
</div>
<footer class="site-footer"><div class="site-width">© {{ date('Y') }} MediQueue · 医院预约挂号课程设计</div></footer>
</body>
</html>
