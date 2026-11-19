@extends('layouts.app')
@section('title', '预约详情')
@section('content')
    <a class="text-link" href="{{ route('appointments.index') }}">返回我的预约</a>
    <h1 class="page-title mt-4">预约详情</h1>
    <div class="mt-5 grid items-start gap-5 lg:grid-cols-2">
        <section class="panel">
            <h2 class="section-title">就诊信息</h2>
            <dl class="record-list mt-3">
                <div><dt>预约状态</dt><dd><x-resource-status :value="$appointment->status" /></dd></div>
                <div><dt>预约编号</dt><dd class="break-all">{{ $appointment->appointment_no }}</dd></div>
                <div><dt>出诊科室</dt><dd>{{ $appointment->department_name }}</dd></div>
                <div><dt>医生</dt><dd>{{ $appointment->doctor_name }}</dd></div>
                <div><dt>就诊时间（北京时间）</dt><dd>{{ \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</dd></div>
            </dl>
            @if(in_array($appointment->status, ['PENDING_PAYMENT', 'CONFIRMED'], true) && $appointment->starts_at > now('UTC')->format('Y-m-d H:i:s.v'))
                <form class="mt-4" id="cancel" method="POST" action="{{ route('appointments.cancel', $appointment->id) }}" data-submit-once>
                    @csrf
                    <button class="btn-secondary" data-label="取消预约" type="submit">取消预约</button>
                </form>
            @endif
        </section>
        <section class="panel">
            <h2 class="section-title">模拟微信支付</h2>
            <dl class="record-list mt-3">
                <div><dt>订单状态</dt><dd><x-resource-status :value="$appointment->order_status" /></dd></div>
                <div><dt>订单编号</dt><dd class="break-all">{{ $appointment->order_no }}</dd></div>
                <div><dt>挂号费</dt><dd>¥{{ $appointment->fee_amount }}</dd></div>
                <div><dt>支付截止（北京时间）</dt><dd>{{ $expires->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i:s') }}</dd></div>
            </dl>
            @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID')
                @if($expired)
                    <p class="mt-4 text-amber-900">付款资格已到期，预约状态尚未改变。</p>
                @elseif($suspended)
                    <p class="mt-4 text-amber-900">本时段已暂停接诊，付款资格已失效，预约状态尚未改变。</p>
                @else
                    <p class="mt-4">剩余支付时间（打开页面时）：{{ $remaining }} 秒</p>
                    @if(config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
                        <a class="btn mt-3" href="{{ route('appointments.checkout', $appointment->id) }}">去支付</a>
                    @endif
                @endif
            @endif
            @if($appointment->refund_status)
                <section class="mt-5 border-t border-gray-200 pt-4" id="refund">
                    <h3 class="font-bold">模拟退款</h3>
                    <dl class="record-list mt-2">
                        <div><dt>退款状态</dt><dd><x-resource-status :value="$appointment->refund_status" /></dd></div>
                        <div><dt>退款编号</dt><dd class="break-all">{{ $appointment->refund_no }}</dd></div>
                    </dl>
                    @if($appointment->last_error)<p class="mt-3 text-red-800">{{ $appointment->last_error }}</p>@endif
                </section>
            @endif
        </section>
    </div>
@endsection
