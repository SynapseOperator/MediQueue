@extends('layouts.app')
@section('title', '确认预约')
@section('content')
    <a class="text-link" href="{{ route('doctors.show', $slot->doctor_id) }}">← 返回医生详情</a>
    <div class="mt-6 grid gap-5 lg:grid-cols-[1fr_0.7fr]"><section class="panel"><p class="eyebrow">预约 · 第 1 步</p><h1 class="page-title mt-2">确认出诊信息</h1><p class="page-intro">请核对信息后提交。名额以服务端最终处理结果为准。</p>
        <dl class="mt-7 grid gap-5 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">科室与医生</dt><dd class="mt-1 font-bold">{{ $slot->department_name }} · {{ $slot->doctor_name }} {{ $slot->title }}</dd></div><div><dt class="text-sm text-slate-500">北京时间</dt><dd class="mt-1 font-bold">{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</dd></div><div><dt class="text-sm text-slate-500">患者</dt><dd class="mt-1 font-bold">{{ $patient->full_name }} · {{ $patient->phone }}</dd></div><div><dt class="text-sm text-slate-500">挂号费</dt><dd class="mt-1 text-xl font-bold">¥{{ $slot->fee_amount }}</dd></div></dl>
        @if(! $available)<p class="mt-6 rounded-xl bg-amber-50 p-4 text-amber-900">当前时段可能已满或不可预约，请返回重新选择。</p>@endif
        <form class="mt-8" method="POST" action="{{ route('appointments.store') }}" data-submit-once>@csrf<input type="hidden" name="slot_id" value="{{ $slot->id }}"><input type="hidden" name="request_key" value="{{ $requestKey }}"><button class="btn" data-label="确认预约" type="submit" @disabled(! $available)>确认预约</button></form>
    </section><aside class="surface-muted h-fit"><h2 class="font-bold">提交后会发生什么？</h2><p class="mt-3 text-sm leading-7 text-slate-600">系统创建待支付预约及订单。若提交结果不确定，请先查看「我的预约」，重试时保留原请求。</p><a class="text-link mt-4 inline-block" href="{{ route('appointments.index') }}">查看我的预约 →</a></aside></div>
@endsection
