@extends('layouts.app')
@section('title', '确认预约')
@section('content')
    <a class="text-link" href="{{ route('doctors.show', $slot->doctor_id) }}">返回医生详情</a>
    <h1 class="page-title mt-4">确认预约</h1>
    @include('appointments.steps', ['step' => 2])

    <section class="panel mt-5 max-w-3xl">
        <h2 class="section-title">确认出诊信息</h2>
        <dl class="record-list mt-3">
            <div><dt>就诊人</dt><dd>{{ $patient->full_name }}（{{ $patient->phone }}）</dd></div>
            <div><dt>科室</dt><dd>{{ $slot->department_name }}</dd></div>
            <div><dt>医生</dt><dd>{{ $slot->doctor_name }} · {{ $slot->title }}</dd></div>
            <div><dt>就诊时间（北京时间）</dt><dd>{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</dd></div>
            <div><dt>挂号费</dt><dd class="font-bold">¥{{ $slot->fee_amount }}</dd></div>
        </dl>
        @unless($available)
            <p class="mt-4 text-amber-900" role="alert">该时段已满或暂停预约，请返回选择其他时段。</p>
        @endunless
        <p class="hint mt-4">网络不稳定时，请先到<a class="text-link" href="{{ route('appointments.index') }}">「我的预约」</a>查看是否已预约成功，不要重复提交。</p>
        <form class="mt-4" method="POST" action="{{ route('appointments.store') }}" data-submit-once>
            @csrf
            <input type="hidden" name="slot_id" value="{{ $slot->id }}">
            <input type="hidden" name="request_key" value="{{ $requestKey }}">
            <button class="btn" data-label="确认预约" type="submit" @disabled(! $available)>确认预约</button>
        </form>
    </section>
@endsection
