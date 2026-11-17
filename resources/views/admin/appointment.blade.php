@extends('layouts.app')
@section('title', '处理预约')
@section('content')
    <a class="text-link" href="{{ route('admin.appointments.index') }}">← 预约处理</a>
    <section class="panel mt-6"><p class="eyebrow">管理员 · 逐条核实</p><h1 class="page-title mt-2">处理预约</h1><p class="mt-3 break-all text-sm text-slate-500">{{ $appointment->appointment_no }}</p>
        <p class="surface-muted mt-5 text-sm font-semibold">模拟微信支付／无实际扣款，退款仅为演示。</p>
        <p class="mt-3">预约状态：{{ $appointment->status }} · 订单状态：{{ $appointment->order_status }} · 退款状态：{{ match($appointment->refund_status) { 'PENDING' => '待处理（仅模拟）', 'FAILED' => '失败（仅模拟）', 'SUCCEEDED' => '成功（仅模拟）', default => '无' } }}</p>
        <p class="mt-6 border-t border-slate-100 pt-5">患者：{{ $appointment->patient_name }} · 联系方式：{{ $appointment->patient_phone }}</p>
        <p class="mt-3">科室与医生：{{ $appointment->department_name }} · {{ $appointment->doctor_name }} · 排班状态：{{ $appointment->schedule_status }} · 号源状态：{{ $appointment->slot_status }}</p>
        <p class="mt-3">出诊时间（北京时间）：{{ \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }} · 金额：¥{{ $appointment->fee_amount }}</p>
        @if($appointment->refund_no)<p class="mt-3">退款编号：{{ $appointment->refund_no }}</p>@endif
        @if($appointment->last_error)<p class="mt-3 text-red-700">最近失败信息：{{ $appointment->last_error }}</p>@endif
        @if(in_array($appointment->status, ['PENDING_PAYMENT', 'CONFIRMED'], true))
            <form class="surface-muted mt-6 space-y-3" method="POST" action="{{ route('admin.appointments.cancel', $appointment->id) }}">@csrf
                <label class="field">取消原因<select name="reason_code"><option value="CLINIC_SUSPENDED">停诊</option><option value="ADMIN_CORRECTION">管理更正</option></select></label>
                <label class="field">说明<input name="reason" required maxlength="255"></label>
                <label><input type="checkbox" name="verified_unseen" value="1" required> 已人工核实患者尚未实际就诊</label><button class="btn-secondary" type="submit">逐条取消预约</button>
            </form>
        @endif
        @if($appointment->status === 'CONFIRMED')
            <form class="surface-muted mt-6" method="POST" action="{{ route('admin.appointments.complete', $appointment->id) }}">@csrf
                <label><input type="checkbox" name="verified" value="1" required> 已人工核实患者就诊完成</label><button class="btn ml-3" type="submit">登记完成</button>
            </form>
        @endif
        @if(in_array($appointment->refund_status, ['PENDING', 'FAILED'], true) && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
            <form class="surface-muted mt-6" method="POST" action="{{ route('admin.appointments.refund', $appointment->id) }}">@csrf
                <label class="field">模拟退款结果<select name="scenario"><option value="success">成功</option><option value="failed">失败</option><option value="unknown">结果未知</option></select></label>
                <button class="btn mt-3" type="submit">处理模拟退款（无实际资金流转）</button>
            </form>
        @endif
    </section>
@endsection
