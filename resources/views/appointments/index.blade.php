@extends('layouts.app')
@section('title', '我的预约')
@section('content')
    <div class="section-head">
        <h1 class="page-title">我的预约</h1>
        <a class="btn" href="{{ route('doctors.index') }}">预约挂号</a>
    </div>
    <form class="filter-bar mt-6" method="GET" action="{{ route('appointments.index') }}">
        <label class="field">预约状态
            <select name="status"><option value="">全部状态</option>
                @foreach(['PENDING_PAYMENT'=>'待支付','CONFIRMED'=>'已确认','CANCELLED'=>'已取消','EXPIRED'=>'已过期','COMPLETED'=>'已完成'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn-secondary" type="submit">筛选</button>
        <a class="text-link" href="{{ route('appointments.index') }}">清除筛选</a>
    </form>
    <p class="hint mt-4">就诊时间均为北京时间。</p>
    @php($nowUtc = \Carbon\CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'))
    @php($zone = config('mediqueue.hospital_timezone'))
    <div class="ticket-list mt-3">
        @forelse($appointments as $appointment)
            @php($start = \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone($zone))
            @php($end = \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone($zone))
            <article class="ticket" data-status="{{ $appointment->status }}" data-refund="{{ $appointment->refund_status }}"
                     aria-label="{{ $start->format('Y-m-d H:i') }} {{ $appointment->department_name }} {{ $appointment->doctor_name }}">
                <div class="ticket-main">
                    <p class="ticket-date">{{ $start->locale('zh_CN')->isoFormat('YYYY年M月D日 ddd') }}</p>
                    <p class="ticket-time">{{ $start->format('H:i') }}–{{ $end->format('H:i') }}</p>
                    <h2 class="ticket-where"><span>{{ $appointment->department_name }}</span><span>{{ $appointment->doctor_name }}</span></h2>
                    <x-resource-status :value="$appointment->status" />
                    <p class="ticket-no">预约编号 <span class="num">{{ $appointment->appointment_no }}</span></p>
                </div>
                <div class="ticket-stub">
                    <p class="ticket-fee">
                        <span>挂号费 <span class="num">¥{{ $appointment->fee_amount }}</span></span>
                        <x-resource-status :value="$appointment->order_status" variant="text" />
                        @if($appointment->refund_status)<x-resource-status :value="$appointment->refund_status" variant="text" />@endif
                    </p>
                    <div class="ticket-actions">
                        @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $appointment->expires_at > $nowUtc && $appointment->resources_open && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
                            <a class="btn btn-sm" href="{{ route('appointments.checkout', $appointment->id) }}">去支付</a>
                        @endif
                        <a class="text-link" href="{{ route('appointments.show', $appointment->id) }}">预约详情</a>
                        @if($appointment->refund_status)
                            <a class="text-link" href="{{ route('appointments.show', $appointment->id) }}#refund">查看退款</a>
                        @endif
                        @if(($appointment->status === 'CONFIRMED' || ($appointment->status === 'PENDING_PAYMENT' && $appointment->expires_at > $nowUtc)) && $appointment->starts_at > $nowUtc)
                            <a class="link-danger" href="{{ route('appointments.show', $appointment->id) }}#cancel">取消预约</a>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="empty-state">
                <p>暂无符合条件的预约。</p>
                <p class="mt-2"><a class="text-link" href="{{ route('doctors.index') }}">去找医生预约挂号</a></p>
            </div>
        @endforelse
    </div>
    <div class="mt-6">{{ $appointments->links() }}</div>
@endsection
