<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '医院预约挂号') · MediQueue</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">
    <a class="sr-only focus:not-sr-only" href="#main-content">跳到主要内容</a>
    <header class="border-b border-slate-200 bg-white">
        <nav class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4" aria-label="主导航">
            <a class="mr-auto text-xl font-bold tracking-tight text-teal-800" href="{{ route('home') }}">MediQueue <span class="text-xs font-normal text-slate-500">医院预约挂号</span></a>
            <a class="nav-link" href="{{ route('departments.index') }}">科室</a>
            <a class="nav-link" href="{{ route('doctors.index') }}">找医生</a>
            @auth
                @if(auth()->user()->role === 'PATIENT')
                    <a class="nav-link" href="{{ route('patient.profile.edit') }}">我的资料</a>
                    <a class="nav-link" href="{{ route('appointments.index') }}">我的预约</a>
                @elseif(auth()->user()->role === 'DOCTOR')
                    <a class="nav-link" href="{{ route('doctor.workbench') }}">医生工作台</a>
                @elseif(auth()->user()->role === 'ADMIN')
                    <a class="nav-link" href="{{ route('admin.resources') }}">资源管理</a>
                @endif
                <span class="text-sm text-slate-500">{{ auth()->user()->username }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link" type="submit">退出</button></form>
            @else
                <a class="nav-link" href="{{ route('login') }}">登录</a>
                <a class="btn" href="{{ route('register') }}">患者注册</a>
            @endauth
        </nav>
    </header>
    <main id="main-content" class="mx-auto max-w-6xl px-5 py-8 sm:py-12">
        @if(session('status'))<p class="mb-6 rounded-xl border border-teal-200 bg-teal-50 p-4 text-teal-900" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-red-900" role="alert">
                <p class="font-semibold">请检查以下内容：</p>
                <ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>
    <footer class="mx-auto max-w-6xl border-t border-slate-200 px-5 py-6 text-sm leading-6 text-slate-500">
        <p>课程演示 · 仅使用虚构资料，不用于真实临床服务。</p>
        <p>模拟微信支付 · 无实际扣款。当前 P3 阶段尚未开放付款或退款。所有出诊时间按北京时间（Asia/Shanghai）显示。</p>
    </footer>
</body>
</html>
