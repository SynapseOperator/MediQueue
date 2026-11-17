@extends('layouts.app')
@section('title', '预约处理')
@section('content')
    <h1 class="page-title">预约处理</h1><p class="mt-3">模拟微信支付／无实际扣款；退款须逐条处理。</p>
    <div class="mt-6 space-y-3">@forelse($appointments as $appointment)
        <div class="panel"><a class="text-link" href="{{ route('admin.appointments.show', $appointment->id) }}">{{ $appointment->appointment_no }}</a><p>预约 {{ $appointment->status }} · 订单 {{ $appointment->order_status }} · 退款 {{ $appointment->refund_status ?? '无' }}</p></div>
    @empty<p>暂无预约。</p>@endforelse</div><div class="mt-6">{{ $appointments->links() }}</div>
@endsection
