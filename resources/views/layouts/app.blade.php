<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fb">
    <title>@yield('title', '医院预约挂号') · MediQueue</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans">
    <a class="sr-only focus:not-sr-only" href="#main-content">跳到主要内容</a>
    <header class="site-header">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-5 px-4 py-3.5 sm:px-6 lg:px-8">
            <a class="site-brand shrink-0" href="{{ route('home') }}" aria-label="MediQueue 首页">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="23" height="23" fill="none">
                        <path d="M12 4v16M4 12h16" stroke="white" stroke-width="3.2" stroke-linecap="round"/>
                    </svg>
                </span>
                <span class="flex flex-col leading-tight">
                    <span class="text-[1.15rem] sm:text-xl">MediQueue</span>
                    <span class="mt-0.5 text-[.62rem] font-medium tracking-[.12em] text-slate-500">医院预约 · 课程演示</span>
                </span>
            </a>

            <nav class="hidden items-center gap-0.5 xl:flex" aria-label="主导航">
                <a class="nav-link {{ request()->routeIs('departments.*') ? 'is-active' : '' }}" href="{{ route('departments.index') }}">科室</a>
                <a class="nav-link {{ request()->routeIs('doctors.*') ? 'is-active' : '' }}" href="{{ route('doctors.index') }}">找医生</a>
                @auth
                    @if(auth()->user()->role === 'PATIENT')
                        <a class="nav-link {{ request()->routeIs('appointments.*') ? 'is-active' : '' }}" href="{{ route('appointments.index') }}">我的预约</a>
                        <a class="nav-link {{ request()->routeIs('patient.profile.*') ? 'is-active' : '' }}" href="{{ route('patient.profile.edit') }}">我的资料</a>
                    @elseif(auth()->user()->role === 'DOCTOR')
                        <a class="nav-link {{ request()->routeIs('doctor.*') ? 'is-active' : '' }}" href="{{ route('doctor.workbench') }}">医生工作台</a>
                    @elseif(auth()->user()->role === 'ADMIN')
                        <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}" href="{{ route('admin.dashboard') }}">管理看板</a>
                        <a class="nav-link {{ request()->routeIs('admin.resources') ? 'is-active' : '' }}" href="{{ route('admin.resources') }}">资源管理</a>
                        <a class="nav-link {{ request()->routeIs('admin.analytics') ? 'is-active' : '' }}" href="{{ route('admin.analytics') }}">统计</a>
                        <a class="nav-link {{ request()->routeIs('admin.audit') ? 'is-active' : '' }}" href="{{ route('admin.audit') }}">审计</a>
                        <a class="nav-link {{ request()->routeIs('admin.appointments.*') ? 'is-active' : '' }}" href="{{ route('admin.appointments.index') }}">预约处理</a>
                    @endif
                @endauth
            </nav>

            <div class="hidden shrink-0 items-center gap-3 xl:flex">
                @auth
                    <span class="nav-user" title="{{ auth()->user()->username }}">{{ auth()->user()->username }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="nav-link" type="submit">退出</button>
                    </form>
                @else
                    <a class="nav-link" href="{{ route('login') }}">登录</a>
                    <a class="btn" href="{{ route('register') }}">患者注册</a>
                @endauth
            </div>

            <details class="nav-mobile xl:hidden">
                <summary aria-label="展开导航菜单">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    菜单
                </summary>
                <nav class="nav-drawer" aria-label="移动端导航">
                    <a class="nav-link {{ request()->routeIs('departments.*') ? 'is-active' : '' }}" href="{{ route('departments.index') }}">科室</a>
                    <a class="nav-link {{ request()->routeIs('doctors.*') ? 'is-active' : '' }}" href="{{ route('doctors.index') }}">找医生</a>
                    @auth
                        @if(auth()->user()->role === 'PATIENT')
                            <a class="nav-link {{ request()->routeIs('appointments.*') ? 'is-active' : '' }}" href="{{ route('appointments.index') }}">我的预约</a>
                            <a class="nav-link {{ request()->routeIs('patient.profile.*') ? 'is-active' : '' }}" href="{{ route('patient.profile.edit') }}">我的资料</a>
                        @elseif(auth()->user()->role === 'DOCTOR')
                            <a class="nav-link {{ request()->routeIs('doctor.*') ? 'is-active' : '' }}" href="{{ route('doctor.workbench') }}">医生工作台</a>
                        @elseif(auth()->user()->role === 'ADMIN')
                            <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}" href="{{ route('admin.dashboard') }}">管理看板</a>
                            <a class="nav-link {{ request()->routeIs('admin.resources') ? 'is-active' : '' }}" href="{{ route('admin.resources') }}">资源管理</a>
                            <a class="nav-link {{ request()->routeIs('admin.analytics') ? 'is-active' : '' }}" href="{{ route('admin.analytics') }}">统计</a>
                            <a class="nav-link {{ request()->routeIs('admin.audit') ? 'is-active' : '' }}" href="{{ route('admin.audit') }}">审计</a>
                            <a class="nav-link {{ request()->routeIs('admin.appointments.*') ? 'is-active' : '' }}" href="{{ route('admin.appointments.index') }}">预约处理</a>
                        @endif
                        <div class="my-2 border-t border-slate-100"></div>
                        <p class="px-4 py-2 text-xs text-slate-500">当前账号 · <span class="break-all">{{ auth()->user()->username }}</span></p>
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button class="nav-link w-full text-left" type="submit">退出登录</button>
                        </form>
                    @else
                        <div class="my-2 border-t border-slate-100"></div>
                        <a class="nav-link" href="{{ route('login') }}">登录</a>
                        <a class="btn mt-2 w-full" href="{{ route('register') }}">患者注册</a>
                    @endauth
                </nav>
            </details>
        </div>
    </header>

    <main id="main-content" class="mx-auto min-h-[65vh] max-w-7xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8 lg:py-12">
        @if(session('status'))
            <p class="mb-6 rounded-2xl border border-teal-200 bg-teal-50 px-5 py-4 text-sm text-teal-900" role="status">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900" role="alert">
                <p class="font-semibold">请检查以下内容：</p>
                <ul class="mt-2 list-inside list-disc space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="mt-12 border-t border-[#e2eaf0] bg-white/70">
        <div class="mx-auto flex max-w-7xl flex-col justify-between gap-4 px-4 py-8 text-xs leading-6 text-slate-500 sm:px-6 lg:flex-row lg:items-center lg:px-8">
            <div>
                <p class="text-sm font-bold text-slate-700">MediQueue <span class="ml-2 font-normal text-slate-500">让预约更清晰</span></p>
                <p>课程演示项目 · 所有患者、医生和医院数据均为虚构，不用于真实临床服务。</p>
            </div>
            <div class="lg:max-w-sm lg:text-right">
                <p class="font-semibold text-teal-800">模拟微信支付 · 无实际扣款</p>
                <p>所有付款与退款仅是演示状态，出诊时间按北京时间显示。</p>
            </div>
        </div>
    </footer>
</body>
</html>
