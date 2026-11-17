@extends('layouts.app')
@section('title', '预约详情')
@section('content')
    <a class="text-link" href="{{ route('appointments.index') }}">← 我的预约</a>
    <div class="mt-6 grid gap-5 lg:grid-cols-[1.25fr_0.75fr]">
        <section class="panel"><p class="eyebrow">预约记录</p><h1 class="page-title mt-2">预约详情</h1><p class="mt-3 text-sm text-slate-500">编号 {{ $appointment->appointment_no }}</p>
            <div class="mt-6 flex flex-wrap gap-2"><x-resource-status :value="$appointment->status" /><x-resource-status :value="$appointment->order_status" /></div>
            <dl class="mt-7 grid gap-5 border-t border-slate-100 pt-6 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500">科室与医生</dt><dd class="mt-1 font-semibold">{{ $appointment->department_name }} · {{ $appointment->doctor_name }}</dd></div>
                <div><dt class="text-sm text-slate-500">出诊时间（北京时间）</dt><dd class="mt-1 font-semibold">{{ \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</dd></div>
                <div><dt class="text-sm text-slate-500">挂号费</dt><dd class="mt-1 text-xl font-bold">¥{{ $appointment->fee_amount }}</dd></div>
                <div><dt class="text-sm text-slate-500">截止时间（北京时间）</dt><dd class="mt-1 font-semibold">{{ $expires->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i:s') }}</dd></div>
            </dl>
            @if(in_array($appointment->status, ['PENDING_PAYMENT', 'CONFIRMED'], true))<form class="mt-8 border-t border-slate-100 pt-6" method="POST" action="{{ route('appointments.cancel', $appointment->id) }}" data-submit-once>@csrf<button class="btn-secondary" data-label="取消预约" type="submit">取消预约</button></form>@endif
        </section>
        <aside class="panel h-fit"><p class="eyebrow">订单与退款</p><h2 class="section-title mt-2">模拟微信支付</h2><p class="mt-3 text-sm leading-6 text-slate-600">仅供课程演示，无实际扣款或资金流转。</p>
            @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID')
                @if($expired)<p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">付款资格已到期；预约待回收，当前状态尚未改变。</p>
                @elseif($suspended)<p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">出诊资源已停用或停诊，付款资格已失效；预约状态尚未改变。</p>
                @else<p class="mt-5 text-sm text-slate-600">服务端计算的截止剩余时间：{{ $remaining }} 秒（页面加载时）。</p>@endif
            @endif
            @if($appointment->refund_status)<div class="surface-muted mt-5"><p class="font-semibold">退款状态：{{ match($appointment->refund_status) { 'PENDING' => '待处理（仅模拟）', 'FAILED' => '失败（仅模拟）', 'SUCCEEDED' => '成功（仅模拟）' } }}</p><p class="mt-2 break-all text-xs text-slate-500">{{ $appointment->refund_no }} · 管理员逐条处理</p>@if($appointment->last_error)<p class="mt-3 text-sm text-red-700">{{ $appointment->last_error }}</p>@endif</div>@endif
            @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && ! $expired && ! $suspended && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
                <a class="btn mt-5 w-full" href="{{ route('appointments.checkout', $appointment->id) }}">进入模拟收银台</a>
            @endif
        </aside>
    </div>
@endsection
