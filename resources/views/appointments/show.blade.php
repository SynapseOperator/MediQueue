@extends('layouts.app')
@section('title', '预约详情')
@section('content')
    @php($zone = config('mediqueue.hospital_timezone'))
    @php($start = \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone($zone))
    @php($end = \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone($zone))
    <a class="text-link back-link" href="{{ route('appointments.index') }}">返回我的预约</a>
    <h1 class="page-title mt-2">预约详情</h1>

    <article class="ticket narrow mt-6" data-status="{{ $appointment->status }}" data-refund="{{ $appointment->refund_status }}" aria-labelledby="visit-title">
        <div class="ticket-main">
            <p class="ticket-date">{{ $start->locale('zh_CN')->isoFormat('YYYY年M月D日 ddd') }}（北京时间）</p>
            <p class="ticket-time">{{ $start->format('H:i') }}–{{ $end->format('H:i') }}</p>
            <h2 id="visit-title" class="ticket-where"><span>{{ $appointment->department_name }}</span><span>{{ $appointment->doctor_name }}</span></h2>
            <x-resource-status :value="$appointment->status" />
            <p class="ticket-no">预约编号 <span class="num">{{ $appointment->appointment_no }}</span></p>
        </div>
        @if(($appointment->status === 'CONFIRMED' || ($appointment->status === 'PENDING_PAYMENT' && ! $expired)) && $appointment->starts_at > now('UTC')->format('Y-m-d H:i:s.v'))
            <div class="ticket-stub">
                <p class="hint">就诊开始前可以取消。已付款的预约取消后将办理退款。</p>
                <form id="cancel" method="POST" action="{{ route('appointments.cancel', $appointment->id) }}" data-submit-once>
                    @csrf
                    <button class="btn-danger" data-label="取消预约" type="submit">取消预约</button>
                </form>
            </div>
        @endif
    </article>

    <section class="panel narrow mt-6" aria-labelledby="payment-title">
        <h2 id="payment-title" class="section-title">模拟微信支付</h2>
        <dl class="record-list mt-3">
            <div><dt>订单状态</dt><dd><x-resource-status :value="$appointment->order_status" /></dd></div>
            <div><dt>订单编号</dt><dd class="num break-all">{{ $appointment->order_no }}</dd></div>
            <div><dt>挂号费</dt><dd class="num">¥{{ $appointment->fee_amount }}</dd></div>
            <div><dt>支付截止（北京时间）</dt><dd class="num">{{ $expires->setTimezone($zone)->format('Y-m-d H:i:s') }}</dd></div>
        </dl>
        @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID')
            @if($expired)
                <p class="alert-error mt-4">付款资格已到期，预约状态尚未改变。</p>
            @elseif($suspended)
                <p class="alert-error mt-4">本时段已暂停接诊，付款资格已失效，预约状态尚未改变。</p>
            @else
                <p class="mt-4">剩余支付时间（打开页面时）：<span class="num">{{ $remaining }}</span> 秒</p>
                @if(config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
                    <a class="btn mt-3" href="{{ route('appointments.checkout', $appointment->id) }}">去支付</a>
                @endif
            @endif
        @endif
        @if($appointment->refund_status)
            <section class="sub-section" id="refund">
                <h3 class="sub-title">模拟退款</h3>
                <dl class="record-list mt-2">
                    <div><dt>退款状态</dt><dd><x-resource-status :value="$appointment->refund_status" /></dd></div>
                    <div><dt>退款编号</dt><dd class="num break-all">{{ $appointment->refund_no }}</dd></div>
                </dl>
                @if($appointment->last_error)<p class="alert-error mt-3">{{ $appointment->last_error }}</p>@endif
            </section>
        @endif
    </section>
@endsection
