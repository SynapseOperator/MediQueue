@extends('layouts.app')
@section('title', '模拟收银台')
@section('content')
    <a class="text-link" href="{{ route('appointments.show', $appointment->id) }}">← 返回预约详情</a>
    <section class="panel mx-auto mt-6 max-w-xl border-t-4 border-t-teal-700"><p class="eyebrow">预约 · 第 2 步</p><h1 class="page-title mt-2">模拟收银台</h1><p class="mt-3 text-slate-600">模拟微信支付／无实际扣款，也不会产生真实资金交易。</p>
        <div class="surface-muted mt-6"><p class="text-sm text-slate-500">模拟支付金额</p><p class="mt-1 text-3xl font-bold text-teal-900">¥{{ $appointment->fee_amount }}</p><p class="mt-3 text-sm">{{ $appointment->department_name }} · {{ $appointment->doctor_name }}</p><p class="mt-2 break-all text-xs text-slate-500">订单 {{ $appointment->order_no }}</p></div>
        @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $expired)<p class="mt-5 text-amber-800">付款资格已到期；预约待回收，当前状态尚未改变。</p>
        @elseif($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $suspended)<p class="mt-5 text-amber-800">出诊资源已停用或停诊，付款资格已失效。</p>
        @elseif($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
            <p class="mt-5 text-sm text-slate-600">服务端计算的剩余时间：{{ $remaining }} 秒（页面加载时）。结果未知时请返回预约详情核实后重试。</p>
            <form class="mt-5" method="POST" action="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $appointment->id]) }}" data-submit-once>@csrf<label class="field">选择演示结果<select name="scenario"><option value="success">成功</option><option value="declined">拒绝</option><option value="unknown">结果未知</option></select></label><button class="btn mt-5 w-full" data-label="确认模拟支付" type="submit">确认模拟支付</button></form>
        @else<p class="mt-5 text-slate-600">此订单当前不能发起模拟支付，请返回预约详情查看状态。</p>@endif
    </section>
@endsection
