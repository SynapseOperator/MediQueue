@extends('layouts.app')
@section('title', '确认预约')
@section('content')
    @php($zone = config('mediqueue.hospital_timezone'))
    @php($start = \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone($zone))
    @php($end = \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone($zone))
    <a class="text-link back-link" href="{{ route('doctors.show', $slot->doctor_id) }}">返回医生详情</a>
    <h1 class="page-title mt-2">确认预约</h1>
    <div class="mt-4">@include('appointments.steps', ['step' => 2])</div>

    <section class="narrow mt-8" aria-labelledby="confirm-title">
        <h2 id="confirm-title" class="section-title">确认出诊信息</h2>
        <article class="ticket mt-3">
            <div class="ticket-main">
                <p class="ticket-date">{{ $start->locale('zh_CN')->isoFormat('YYYY年M月D日 ddd') }}（北京时间）</p>
                <p class="ticket-time">{{ $start->format('H:i') }}–{{ $end->format('H:i') }}</p>
                <p class="ticket-where"><span>{{ $slot->department_name }}</span><span>{{ $slot->doctor_name }}，{{ $slot->title }}</span></p>
            </div>
            <dl class="ticket-stub ticket-facts">
                <div><dt>就诊人</dt><dd>{{ $patient->full_name }}（<span class="num">{{ $patient->phone }}</span>）</dd></div>
                <div><dt>挂号费</dt><dd class="num">¥{{ $slot->fee_amount }}</dd></div>
            </dl>
        </article>
        @unless($available)
            <p class="alert-error mt-4" role="alert">该时段已满或暂停预约，请返回选择其他时段。</p>
        @endunless
        <form class="mt-6" method="POST" action="{{ route('appointments.store') }}" data-submit-once>
            @csrf
            <input type="hidden" name="slot_id" value="{{ $slot->id }}">
            <input type="hidden" name="request_key" value="{{ $requestKey }}">
            <button class="btn btn-lg" data-label="确认预约" type="submit" @disabled(! $available)>确认预约</button>
        </form>
        <p class="hint mt-3">确认后请在预约详情显示的截止时间前付款，最晚到就诊开始时。网络不稳定时，请先到<a class="text-link" href="{{ route('appointments.index') }}">「我的预约」</a>查看是否已预约成功，不要重复提交。</p>
    </section>
@endsection
