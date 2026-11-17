@extends('layouts.app')
@section('title', '预约详情')
@section('content')
    <a class="text-link" href="{{ route('appointments.index') }}">← 我的预约</a>
    <section class="panel mt-6">
        <h1 class="page-title">预约详情</h1>
        <dl class="mt-5 space-y-3">
            <div><dt>预约编号</dt><dd>{{ $appointment->appointment_no }}</dd></div>
            <div><dt>科室与医生</dt><dd>{{ $appointment->department_name }} · {{ $appointment->doctor_name }}</dd></div>
            <div><dt>出诊时间（北京时间）</dt><dd>{{ \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</dd></div>
            <div><dt>预约状态</dt><dd>{{ $appointment->status }}</dd></div>
            <div><dt>订单状态</dt><dd>{{ $appointment->order_status === 'UNPAID' ? 'UNPAID / 待支付' : $appointment->order_status }}</dd></div>
            <div><dt>金额</dt><dd>¥{{ $appointment->fee_amount }}</dd></div>
            <div><dt>截止时间（北京时间）</dt><dd>{{ $expires->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i:s') }}</dd></div>
        </dl>
        @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID')
            @if($expired)<p class="mt-5 text-red-700">付款资格已到期；预约待回收，当前状态尚未改变。</p>
            @elseif($suspended)<p class="mt-5 text-red-700">出诊资源已停用或停诊，付款资格已失效；预约状态尚未改变。</p>
            @else<p class="mt-5">服务端计算的截止剩余时间：{{ $remaining }} 秒（页面加载时）。</p>@endif
        @endif
        <p class="mt-5 font-semibold">模拟微信支付／无实际扣款</p>
        @if($appointment->refund_status)
            <p class="mt-3">退款状态：{{ match($appointment->refund_status) { 'PENDING' => '待处理（仅模拟）', 'FAILED' => '失败（仅模拟）', 'SUCCEEDED' => '成功（仅模拟）' } }}（{{ $appointment->refund_no }}）。退款由管理员逐条处理。</p>
            @if($appointment->last_error)<p class="mt-3 text-red-700">{{ $appointment->last_error }}</p>@endif
        @endif
        @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && ! $expired && ! $suspended && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
            <form class="mt-5" method="POST" action="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $appointment->id]) }}">@csrf
                <label class="field">模拟结果<select name="scenario"><option value="success">成功</option><option value="declined">拒绝</option><option value="unknown">结果未知</option></select></label>
                <button class="btn mt-3" type="submit">模拟微信支付（无实际扣款）</button>
            </form>
        @endif
        @if(in_array($appointment->status, ['PENDING_PAYMENT', 'CONFIRMED'], true))
            <form class="mt-5" method="POST" action="{{ route('appointments.cancel', $appointment->id) }}">@csrf<button class="btn-secondary" type="submit">取消预约</button></form>
        @endif
    </section>
@endsection
