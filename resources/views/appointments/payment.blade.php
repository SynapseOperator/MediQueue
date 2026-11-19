@extends('layouts.app')
@section('title', '模拟收银台')
@section('content')
    <a class="text-link" href="{{ route('appointments.show', $appointment->id) }}">返回预约详情</a>
    <h1 class="page-title mt-4">模拟收银台</h1>
    @include('appointments.steps', ['step' => 3])

    <section class="panel mt-5 max-w-3xl">
        <h2 class="section-title">应付挂号费</h2>
        <p class="mt-2 text-3xl font-bold">¥{{ $appointment->fee_amount }}</p>
        <p class="hint mt-2">模拟微信支付，仅供演示，无实际扣款。</p>
        <dl class="record-list mt-4">
            <div><dt>科室及医生</dt><dd>{{ $appointment->department_name }} · {{ $appointment->doctor_name }}</dd></div>
            <div><dt>订单编号</dt><dd class="break-all">{{ $appointment->order_no }}</dd></div>
            <div><dt>支付截止（北京时间）</dt><dd>{{ $expires->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i:s') }}</dd></div>
        </dl>
        @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $expired)
            <p class="mt-4 text-amber-900" role="alert">付款资格已到期，预约待回收。</p>
        @elseif($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $suspended)
            <p class="mt-4 text-amber-900" role="alert">本时段已暂停接诊，付款资格已失效。</p>
        @elseif($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
            <p class="mt-4">剩余支付时间（打开页面时）：{{ $remaining }} 秒</p>
            <form class="mt-4 flex flex-wrap gap-2" method="POST"
                  action="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $appointment->id]) }}" data-submit-once>
                @csrf
                <button class="btn" name="scenario" value="success" type="submit" data-label="模拟成功">模拟成功</button>
                <button class="btn-secondary" name="scenario" value="declined" type="submit" data-label="模拟拒绝">模拟拒绝</button>
                <button class="btn-secondary" name="scenario" value="unknown" type="submit" data-label="模拟结果未知">模拟结果未知</button>
            </form>
            <p class="hint mt-3">如付款结果暂未确认，请返回预约详情查看订单状态。</p>
        @else
            <p class="mt-4">该订单当前不可支付，请返回预约详情查看状态。</p>
        @endif
    </section>
@endsection
