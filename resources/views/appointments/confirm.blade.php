@extends('layouts.app')
@section('title', '确认预约')
@section('content')
    <a class="text-link text-sm" href="{{ route('doctors.show', $slot->doctor_id) }}">← 返回医生详情</a>

    <div class="mt-7 flex flex-wrap items-center gap-2 text-xs font-bold">
        <span class="step-pill">01 · 选择时段</span>
        <span class="text-slate-300" aria-hidden="true">/</span>
        <span class="step-pill">02 · 核对预约</span>
        <span class="text-slate-300" aria-hidden="true">/</span>
        <span class="rounded-full border border-slate-200 px-3 py-1.5 text-slate-400">03 · 模拟支付</span>
    </div>
    <div class="mt-6 grid items-start gap-6 lg:grid-cols-[1.3fr_.7fr]">
        <section class="panel">
            <p class="eyebrow">APPOINTMENT REVIEW · 预约确认</p>
            <h1 class="page-title mt-2">确认出诊信息</h1>
            <p class="page-intro">核对医生、就诊时间和患者资料。正式占用名额将在提交后由服务器处理。</p>

            <div class="mt-7 rounded-2xl border border-[#e2ecf1] bg-[#f6fafc] p-5">
                <p class="text-xs font-bold tracking-wider text-[#18758a]">您选择的医生</p>
                <div class="mt-3 flex items-center gap-3">
                    <div class="doctor-avatar size-14 shrink-0 text-lg" aria-hidden="true">医</div>
                    <div>
                        <p class="text-lg font-bold text-slate-800">{{ $slot->doctor_name }} <span class="text-sm font-normal text-slate-500">{{ $slot->title }}</span></p>
                        <p class="mt-1 text-sm text-slate-500">{{ $slot->department_name }}</p>
                    </div>
                </div>
            </div>

            <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                <div class="rounded-xl bg-white p-2">
                    <dt class="text-sm text-slate-500">出诊时间 · 北京时间</dt>
                    <dd class="mt-2 text-base font-bold leading-7 text-slate-800">{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</dd>
                </div>
                <div class="rounded-xl bg-white p-2">
                    <dt class="text-sm text-slate-500">患者资料</dt>
                    <dd class="mt-2 text-base font-bold text-slate-800">{{ $patient->full_name }}</dd>
                    <dd class="mt-1 text-sm text-slate-500">{{ $patient->phone }}</dd>
                </div>
            </dl>
            @if(! $available)
                <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert">当前时段可能已满或不可预约，请返回重新选择。</p>
            @endif
            <p class="mt-6 border-t border-slate-100 pt-5 text-xs leading-6 text-slate-500">提交结果如果暂时未知，请先在「我的预约」检查记录；使用原请求标识重试，不要重复创建新预约。</p>
        </section>

        <aside class="panel lg:sticky lg:top-28">
            <p class="eyebrow">ORDER SUMMARY · 本次挂号</p>
            <h2 class="mt-2 text-lg font-bold text-slate-800">费用确认</h2>
            <div class="mt-5 flex items-baseline justify-between border-b border-slate-100 pb-5">
                <span class="text-sm text-slate-500">挂号费</span>
                <span class="text-3xl font-bold tracking-tight text-[#125d75]">¥{{ $slot->fee_amount }}</span>
            </div>
            <div class="mt-5 space-y-3 text-sm leading-6 text-slate-600">
                <p class="flex items-start gap-2"><span class="text-[#168296]" aria-hidden="true">✓</span> 提交成功后创建待支付预约及订单。</p>
                <p class="flex items-start gap-2"><span class="text-[#168296]" aria-hidden="true">✓</span> 实际付款截止以订单详情为准，最长 15 分钟。</p>
            </div>
            <form class="mt-6" method="POST" action="{{ route('appointments.store') }}" data-submit-once>
                @csrf
                <input type="hidden" name="slot_id" value="{{ $slot->id }}">
                <input type="hidden" name="request_key" value="{{ $requestKey }}">
                <button class="btn w-full" data-label="确认预约" type="submit" @disabled(! $available)>确认预约 →</button>
            </form>
            <p class="mt-4 text-center text-xs leading-6 text-slate-500">模拟微信支付 · 无实际扣款</p>
            <a class="text-link mt-3 block text-center text-sm" href="{{ route('appointments.index') }}">查看我的预约</a>
        </aside>
    </div>
@endsection
