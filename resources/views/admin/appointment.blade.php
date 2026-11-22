@extends('layouts.app')
@section('title', '处理预约')
@section('content')
    @php($zone = config('mediqueue.hospital_timezone'))
    @php($start = \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone($zone))
    @php($end = \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone($zone))
    <a class="text-link back-link" href="{{ route('admin.appointments.index') }}">返回预约处理</a>
    <h1 class="page-title mt-2">处理预约</h1>

    <article class="ticket mt-6" data-status="{{ $appointment->status }}" data-refund="{{ $appointment->refund_status }}" aria-labelledby="visit-title">
        <div class="ticket-main">
            <p class="ticket-date">{{ $start->locale('zh_CN')->isoFormat('YYYY年M月D日 ddd') }}（北京时间）</p>
            <p class="ticket-time">{{ $start->format('H:i') }}–{{ $end->format('H:i') }}</p>
            <h2 id="visit-title" class="ticket-where"><span>{{ $appointment->department_name }}</span><span>{{ $appointment->doctor_name }}</span></h2>
            <x-resource-status :value="$appointment->status" />
            <p class="ticket-no">预约编号 <span class="num">{{ $appointment->appointment_no }}</span></p>
        </div>
        <dl class="ticket-stub ticket-facts">
            <div><dt>就诊人</dt><dd>{{ $appointment->patient_name }}（<span class="num">{{ $appointment->patient_phone }}</span>）</dd></div>
            <div><dt>排班 / 号源</dt><dd><x-resource-status :value="$appointment->schedule_status" /> / <x-resource-status :value="$appointment->slot_status" /></dd></div>
            <div><dt>订单</dt><dd><span class="num">¥{{ $appointment->fee_amount }}</span> <x-resource-status :value="$appointment->order_status" variant="text" /></dd></div>
            @if($appointment->refund_status)
                <div><dt>模拟退款</dt><dd><x-resource-status :value="$appointment->refund_status" /> <span class="num">{{ $appointment->refund_no }}</span></dd></div>
            @endif
        </dl>
    </article>
    @if($appointment->last_error)<p class="alert-error mt-4"><b>退款反馈</b> {{ $appointment->last_error }}</p>@endif

    <div class="detail-grid mt-6">
        @if(in_array($appointment->refund_status, ['PENDING', 'FAILED'], true) && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
            <section class="panel"><h2 class="section-title">处理模拟退款</h2>
                <form class="filter-bar mt-3" method="POST" action="{{ route('admin.appointments.refund', $appointment->id) }}">
                    @csrf
                    <label class="field">模拟退款结果<select name="scenario" required><option value="success">成功</option><option value="failed">失败</option><option value="unknown">结果未知</option></select></label>
                    <button class="btn" type="submit">提交退款结果</button>
                </form>
            </section>
        @endif
        @if($appointment->status === 'CONFIRMED')
            <section class="panel"><h2 class="section-title">完成登记</h2>
                <form class="mt-3 space-y-4" method="POST" action="{{ route('admin.appointments.complete', $appointment->id) }}">
                    @csrf
                    <label class="check"><input type="checkbox" name="verified" value="1" required> 已人工核实患者就诊完成</label>
                    <button class="btn" type="submit">登记完成</button>
                </form>
            </section>
        @endif
        @if(in_array($appointment->status, ['PENDING_PAYMENT', 'CONFIRMED'], true))
            <section class="panel"><h2 class="section-title">取消预约</h2>
                <form class="mt-3 space-y-4" method="POST" action="{{ route('admin.appointments.cancel', $appointment->id) }}">
                    @csrf
                    <label class="field">取消原因<select name="reason_code" required><option value="CLINIC_SUSPENDED">停诊</option><option value="ADMIN_CORRECTION">管理更正</option></select></label>
                    <label class="field">原因说明（必填）<input name="reason" required maxlength="255" value="{{ $oldInput['reason'] ?? '' }}"></label>
                    <label class="check"><input type="checkbox" name="verified_unseen" value="1" required> 已人工核实患者尚未实际就诊</label>
                    <button class="btn-danger" type="submit">取消此预约</button>
                </form>
            </section>
        @endif
    </div>
@endsection
