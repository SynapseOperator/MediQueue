@extends('layouts.app')
@section('title', '首页')
@section('content')
    <section class="home-hero">
        <div class="grid items-center gap-10 px-6 py-10 sm:px-10 sm:py-14 lg:grid-cols-[1.16fr_.84fr] lg:gap-16 lg:px-14 lg:py-16">
            <div>
                <p class="hero-overline"><span aria-hidden="true" class="size-2 rounded-full bg-teal-500"></span> MEDIQUEUE · 单医院多科室</p>
                <h1 class="hero-display">让每一次就诊，<br><span class="hero-highlight">从清晰的安排开始。</span></h1>
                <p class="mt-6 max-w-xl text-base leading-8 text-slate-600">了解科室和医生，找到合适的时间。预约、模拟支付、取消及退款状态，都能在这里清楚查看。</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a class="btn min-h-12 px-6" href="{{ route('doctors.index') }}">
                        查找医生与时段
                        <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <a class="btn-secondary min-h-12 px-6" href="{{ route('departments.index') }}">浏览全部科室</a>
                </div>
                <p class="mt-5 flex items-center gap-2 text-xs leading-6 text-slate-500">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M12 3 4 6v6c0 5 3.6 8 8 9 4.4-1 8-4 8-9V6l-8-3Z" stroke="#14778c" stroke-width="1.7"/><path d="m9 12 2 2 4-4" stroke="#14778c" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    课程演示环境 · 模拟微信支付，无实际扣款
                </p>
            </div>
            <aside class="relative" aria-label="预约步骤说明">
                <div aria-hidden="true" class="absolute -right-5 -top-5 size-28 rounded-full bg-sky-200/50 blur-2xl"></div>
                <div class="hero-mini-card relative mx-auto max-w-md">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="grid size-11 place-items-center rounded-2xl bg-[#e4f3f8] text-[#177e93]" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="25" height="25" fill="none"><rect x="3" y="5" width="18" height="16" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M7 3v4M17 3v4M3 10h18m-13 5 2 2 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500">预约指南</p>
                                <h2 class="text-lg font-bold text-slate-800">三步完成挂号</h2>
                            </div>
                        </div>
                        <span class="rounded-full bg-[#e6f6f1] px-3 py-1 text-xs font-bold text-teal-700">流程示意</span>
                    </div>
                    <ol class="mt-5 space-y-3">
                        <li class="journey-step"><span class="step-num">01</span><div><p class="font-bold text-slate-800">查看医生与出诊</p><p class="mt-0.5 text-xs text-slate-500">选择科室、医生和可预约时段</p></div></li>
                        <li class="journey-step"><span class="step-num">02</span><div><p class="font-bold text-slate-800">核对预约信息</p><p class="mt-0.5 text-xs text-slate-500">确认挂号费与预约截止时间</p></div></li>
                        <li class="journey-step"><span class="step-num">03</span><div><p class="font-bold text-slate-800">查看进度与结果</p><p class="mt-0.5 text-xs text-slate-500">模拟支付及退款状态清晰可查</p></div></li>
                    </ol>
                    <p class="mt-5 rounded-xl bg-[#f7fafc] px-4 py-3 text-xs leading-6 text-slate-500">支付过程仅用于技术演示，不产生真实交易，也不提供医疗诊断。</p>
                </div>
            </aside>
        </div>
    </section>

    <section class="mt-14 sm:mt-16">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow">DISCOVER DEPARTMENTS · 科室导览</p>
                <h2 class="section-title mt-2">从熟悉的科室开始</h2>
                <p class="page-intro">目前共有 {{ $doctorCount }} 位开放医生，可查看对应科室与未来出诊安排。</p>
            </div>
            <a class="text-link" href="{{ route('departments.index') }}">全部科室 <span aria-hidden="true" class="ml-1">→</span></a>
        </div>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($departments as $department)
                <a class="feature-tile group" href="{{ route('doctors.index', ['department' => $department->id]) }}">
                    <div class="mb-5 flex items-center justify-between">
                        <span class="feature-icon mb-0" aria-hidden="true">
                            <svg width="23" height="23" viewBox="0 0 24 24" fill="none"><path d="M12 3v18M3 12h18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                        </span>
                        <span class="status-badge">开放科室</span>
                    </div>
                    <h3 class="text-xl font-bold text-slate-800">{{ $department->name }}</h3>
                    <p class="mt-2 line-clamp-2 min-h-10 text-sm leading-5 text-slate-500">{{ $department->introduction ?: '查看当前开放的医生与出诊时段。' }}</p>
                    <span class="mt-6 inline-flex items-center text-sm font-bold text-[#14758a]">查看科室医生 <span aria-hidden="true" class="ml-2 transition group-hover:translate-x-1">→</span></span>
                </a>
            @empty
                <p class="empty-state col-span-full">暂无开放科室。可稍后再来查看。</p>
            @endforelse
        </div>
    </section>

    <section class="mt-14 sm:mt-16" aria-label="角色服务说明">
        <p class="eyebrow">ONE PLACE · ONE CLEAR JOURNEY</p>
        <h2 class="section-title mt-2">每个角色，都有清晰的工作入口</h2>
        <div class="mt-6 grid gap-4 md:grid-cols-3">
            <article class="panel">
                <div class="feature-icon">01</div>
                <h3 class="text-lg font-bold text-slate-800">患者 · 自助预约</h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">用虚构资料创建档案，预约医生，并及时查看确认、取消及模拟退款结果。</p>
            </article>
            <article class="panel">
                <div class="feature-icon">02</div>
                <h3 class="text-lg font-bold text-slate-800">医生 · 只读工作台</h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">查看本人出诊与预约名单。排班及号源由管理员集中管理。</p>
            </article>
            <article class="panel">
                <div class="feature-icon">03</div>
                <h3 class="text-lg font-bold text-slate-800">管理员 · 运营管理</h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">维护排班、逐项处理停诊与退款，用明确的数据口径查看统计。</p>
            </article>
        </div>
    </section>
@endsection
