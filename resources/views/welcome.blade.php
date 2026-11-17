@extends('layouts.app')
@section('title', '首页')
@section('content')
    <section class="rounded-3xl bg-teal-950 px-6 py-12 text-white sm:p-14">
        <p class="text-sm font-medium tracking-widest text-teal-200">单医院 · 多科室</p>
        <h1 class="mt-5 max-w-2xl text-3xl font-semibold leading-tight sm:text-5xl">了解医生，<br>从清楚的出诊安排开始。</h1>
        <p class="mt-6 max-w-xl leading-8 text-teal-100">查看科室介绍、医生专长和未来出诊时段。患者可建立自己的演示档案，医生查看本人排班，管理员维护医院资源。</p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a class="rounded-lg bg-white px-5 py-3 font-semibold text-teal-950 hover:bg-teal-100" href="{{ route('doctors.index') }}">浏览医生与出诊安排 →</a>
            <a class="rounded-lg border border-teal-700 px-5 py-3 font-semibold hover:bg-teal-900" href="{{ route('departments.index') }}">查看全部科室</a>
        </div>
    </section>
    <section class="mt-8 grid gap-5 md:grid-cols-3" aria-label="使用说明">
        <article class="panel"><p class="eyebrow">患者</p><h2 class="mt-2 text-lg font-semibold">管理本人资料</h2><p class="mt-3 text-slate-600">注册时填写虚构姓名与联系方式，无需身份证。资料仅由本人维护。</p></article>
        <article class="panel"><p class="eyebrow">医生</p><h2 class="mt-2 text-lg font-semibold">只读出诊工作台</h2><p class="mt-3 text-slate-600">使用管理员建立的账号登录，查看本人排班与时段安排。</p></article>
        <article class="panel"><p class="eyebrow">当前阶段</p><h2 class="mt-2 text-lg font-semibold">P4 · 模拟支付与生命周期</h2><p class="mt-3 text-slate-600">患者可预约并体验模拟微信支付；管理员逐条处理退款。无实际扣款。</p></article>
    </section>
@endsection
