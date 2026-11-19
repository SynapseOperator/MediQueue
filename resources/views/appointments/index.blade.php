@extends('layouts.app')
@section('title', '我的预约')
@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="page-title">我的预约</h1>
        <a class="btn-secondary" href="{{ route('doctors.index') }}">预约挂号</a>
    </div>
    <form class="mt-5 flex flex-wrap items-end gap-3" method="GET" action="{{ route('appointments.index') }}">
        <label class="field">预约状态
            <select name="status"><option value="">全部状态</option>
                @foreach(['PENDING_PAYMENT'=>'待支付','CONFIRMED'=>'已确认','CANCELLED'=>'已取消','EXPIRED'=>'已过期','COMPLETED'=>'已完成'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn" type="submit">筛选</button>
        <a class="text-link" href="{{ route('appointments.index') }}">清除筛选</a>
    </form>
    @php($nowUtc = \Carbon\CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'))
    <div class="mt-5 space-y-3">
        @forelse($appointments as $appointment)
            <article class="panel">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="section-title">{{ $appointment->department_name }} · {{ $appointment->doctor_name }}</h2>
                    <x-resource-status :value="$appointment->status" />
                </div>
                <p class="mt-2">北京时间 {{ \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span>挂号费 ¥{{ $appointment->fee_amount }}</span>
                    <x-resource-status :value="$appointment->order_status" />
                    @if($appointment->refund_status)<x-resource-status :value="$appointment->refund_status" />@endif
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-3">
                    <p class="hint break-all">预约编号 {{ $appointment->appointment_no }}</p>
                    <div class="flex flex-wrap gap-3">
                        <a class="text-link" href="{{ route('appointments.show', $appointment->id) }}">预约详情</a>
                        @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $appointment->expires_at > $nowUtc && $appointment->resources_open && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
                            <a class="text-link" href="{{ route('appointments.checkout', $appointment->id) }}">去支付</a>
                        @endif
                        @if(in_array($appointment->status, ['PENDING_PAYMENT', 'CONFIRMED'], true) && $appointment->starts_at > $nowUtc)
                            <a class="text-link" href="{{ route('appointments.show', $appointment->id) }}#cancel">取消预约</a>
                        @endif
                        @if($appointment->refund_status)
                            <a class="text-link" href="{{ route('appointments.show', $appointment->id) }}#refund">查看退款</a>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <p class="empty-state">暂无符合条件的预约。</p>
        @endforelse
    </div>
    <div class="mt-5">{{ $appointments->links() }}</div>
@endsection
