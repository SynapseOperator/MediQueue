@extends('layouts.app')
@section('title', '首页')
@section('content')
    <section class="overflow-hidden rounded-[2rem] bg-[#123f46] text-white shadow-xl shadow-teal-950/10">
        <div class="grid gap-8 px-7 py-12 sm:px-12 sm:py-16 lg:grid-cols-[1.4fr_0.6fr] lg:items-end">
            <div><p class="text-xs font-bold tracking-[0.2em] text-teal-200">MEDIQUEUE · 单医院多科室</p>
                <h1 class="mt-5 max-w-2xl text-4xl font-bold leading-tight tracking-tight sm:text-5xl">让每一次就诊，<br>从清晰的安排开始。</h1>
                <p class="mt-6 max-w-xl text-base leading-8 text-teal-50/90">浏览科室与医生，选择合适的出诊时段。预约、模拟支付和进度都在一处查看。</p>
                <div class="mt-8 flex flex-wrap gap-3"><a class="inline-flex min-h-12 items-center rounded-xl bg-white px-6 font-bold text-teal-950 hover:bg-teal-50" href="{{ route('doctors.index') }}">查找医生与时段 →</a><a class="inline-flex min-h-12 items-center rounded-xl border border-teal-300/50 px-6 font-semibold hover:bg-white/10" href="{{ route('departments.index') }}">浏览科室</a></div>
            </div>
            <div class="rounded-2xl border border-white/15 bg-white/10 p-6"><p class="text-sm font-semibold text-teal-100">就诊流程</p><ol class="mt-5 space-y-4 text-sm"><li><strong class="mr-3 text-teal-200">01</strong> 选择医生与时段</li><li><strong class="mr-3 text-teal-200">02</strong> 确认预约并模拟支付</li><li><strong class="mr-3 text-teal-200">03</strong> 在「我的预约」查看进度</li></ol><p class="mt-6 border-t border-white/15 pt-4 text-xs leading-6 text-teal-100">课程演示 · 所有资料均为虚构。模拟微信支付，无实际扣款。</p></div>
        </div>
    </section>
    <section class="mt-12"><div class="flex flex-wrap items-end justify-between gap-4"><div><p class="eyebrow">探索医院资源</p><h2 class="section-title mt-2">从科室开始</h2><p class="page-intro">当前有 {{ $doctorCount }} 位开放医生，可按科室浏览。</p></div><a class="text-link" href="{{ route('departments.index') }}">全部科室 →</a></div><div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">@forelse($departments as $department)<a class="panel block hover:border-teal-400" href="{{ route('doctors.index', ['department' => $department->id]) }}"><span class="status-badge">开放科室</span><h3 class="mt-4 text-lg font-bold">{{ $department->name }}</h3></a>@empty<p class="panel">暂无开放科室。</p>@endforelse</div></section>
    <section class="mt-12" aria-label="使用说明"><p class="eyebrow">为不同角色设计</p><h2 class="section-title mt-2">需要的信息，恰好在手边</h2><div class="mt-6 grid gap-4 md:grid-cols-3">
        <article class="panel"><span class="status-badge">患者</span><h3 class="mt-5 text-lg font-bold">管理本人资料与预约</h3><p class="mt-3 leading-7 text-slate-600">使用虚构资料创建档案，随时查看预约和模拟退款状态。</p></article>
        <article class="panel"><span class="status-badge">医生</span><h3 class="mt-5 text-lg font-bold">查看本人出诊安排</h3><p class="mt-3 leading-7 text-slate-600">在只读工作台查看本人排班和时段，排班由管理员维护。</p></article>
        <article class="panel"><span class="status-badge">模拟账务</span><h3 class="mt-5 text-lg font-bold">清晰查看付款与退款状态</h3><p class="mt-3 leading-7 text-slate-600">模拟微信支付与退款仅用于课程演示，无实际扣款。</p></article>
    </div></section>
@endsection
