@extends('layouts.app')
@section('title', '处理预约')
@section('content')
    <a class="text-link" href="{{ route('admin.appointments.index') }}">返回预约处理</a>
    <h1 class="page-title mt-4">处理预约</h1>
    <section class="panel mt-5">
        <h2 class="section-title">就诊信息</h2>
        <dl class="record-list mt-3">
            <div><dt>预约编号</dt><dd class="break-all">{{ $appointment->appointment_no }}</dd></div>
            <div><dt>预约状态</dt><dd><x-resource-status :value="$appointment->status" /></dd></div>
            <div><dt>就诊人</dt><dd>{{ $appointment->patient_name }} · {{ $appointment->patient_phone }}</dd></div>
            <div><dt>科室及医生</dt><dd>{{ $appointment->department_name }} · {{ $appointment->doctor_name }}</dd></div>
            <div><dt>排班 / 号源</dt><dd><x-resource-status :value="$appointment->schedule_status" /> / <x-resource-status :value="$appointment->slot_status" /></dd></div>
            <div><dt>就诊时间（北京时间）</dt><dd>{{ \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</dd></div>
            <div><dt>订单</dt><dd>¥{{ $appointment->fee_amount }} · <x-resource-status :value="$appointment->order_status" /></dd></div>
            @if($appointment->refund_status)
                <div><dt>模拟退款</dt><dd><x-resource-status :value="$appointment->refund_status" /> {{ $appointment->refund_no }}</dd></div>
            @endif
            @if($appointment->last_error)<div><dt>退款反馈</dt><dd class="text-red-800">{{ $appointment->last_error }}</dd></div>@endif
        </dl>
    </section>
    @if(in_array($appointment->status, ['PENDING_PAYMENT', 'CONFIRMED'], true))
        <section class="panel mt-5"><h2 class="section-title">取消预约</h2>
            <form class="mt-3 space-y-3" method="POST" action="{{ route('admin.appointments.cancel', $appointment->id) }}">
                @csrf
                <label class="field">取消原因<select name="reason_code" required><option value="CLINIC_SUSPENDED">停诊</option><option value="ADMIN_CORRECTION">管理更正</option></select></label>
                <label class="field">原因说明（必填）<input name="reason" required maxlength="255" value="{{ $oldInput['reason'] ?? '' }}"></label>
                <label class="block"><input type="checkbox" name="verified_unseen" value="1" required> 已人工核实患者尚未实际就诊</label>
                <button class="btn-secondary" type="submit">取消此预约</button>
            </form>
        </section>
    @endif
    @if($appointment->status === 'CONFIRMED')
        <section class="panel mt-5"><h2 class="section-title">完成登记</h2>
            <form class="mt-3 flex flex-wrap items-center gap-3" method="POST" action="{{ route('admin.appointments.complete', $appointment->id) }}">
                @csrf
                <label><input type="checkbox" name="verified" value="1" required> 已人工核实患者就诊完成</label>
                <button class="btn" type="submit">登记完成</button>
            </form>
        </section>
    @endif
    @if(in_array($appointment->refund_status, ['PENDING', 'FAILED'], true) && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
        <section class="panel mt-5"><h2 class="section-title">处理模拟退款</h2>
            <form class="mt-3 flex flex-wrap items-end gap-3" method="POST" action="{{ route('admin.appointments.refund', $appointment->id) }}">
                @csrf
                <label class="field">模拟退款结果<select name="scenario" required><option value="success">成功</option><option value="failed">失败</option><option value="unknown">结果未知</option></select></label>
                <button class="btn" type="submit">提交退款结果</button>
            </form>
        </section>
    @endif
@endsection
