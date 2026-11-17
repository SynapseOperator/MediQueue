@extends('layouts.app')
@section('title', '模拟收银台')
@section('content')
    <a class="text-link text-sm" href="{{ route('appointments.show', $appointment->id) }}">← 返回预约详情</a>
    <div class="mx-auto mt-7 max-w-2xl">
        <div class="mb-5 flex items-center gap-2 text-xs font-bold">
            <span class="step-pill">01 · 选择时段</span>
            <span class="text-slate-300">/</span>
            <span class="step-pill">02 · 核对预约</span>
            <span class="text-slate-300">/</span>
            <span class="step-pill">03 · 模拟支付</span>
        </div>
        <section class="panel overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">CHECKOUT · 演示支付</p>
                    <h1 class="page-title mt-2">模拟收银台</h1>
                    <p class="mt-3 text-sm leading-7 text-slate-600">用于演示订单状态和事务处理，不会向微信或银行卡发起真实扣款。</p>
                </div>
                <span class="status-badge">仅限本地演示</span>
            </div>

            <div class="mt-7 rounded-[1.25rem] border border-[#cfe4ec] bg-gradient-to-br from-[#f1fafc] to-[#edf5fe] p-6">
                <p class="text-sm font-semibold text-slate-600">模拟支付金额</p>
                <p class="mt-2 text-4xl font-bold tracking-tight text-[#125c74] sm:text-5xl">¥{{ $appointment->fee_amount }}</p>
                <div class="mt-5 border-t border-[#cfe4ec] pt-4 text-sm">
                    <p class="font-bold text-slate-700">{{ $appointment->department_name }} · {{ $appointment->doctor_name }}</p>
                    <p class="mt-2 break-all text-xs text-slate-500">订单 {{ $appointment->order_no }}</p>
                </div>
            </div>

            @if($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $expired)
                <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900" role="alert">付款资格已到期；预约待回收，当前状态尚未改变。</p>
            @elseif($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && $suspended)
                <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900" role="alert">出诊资源已停用或停诊，付款资格已失效。</p>
            @elseif($appointment->status === 'PENDING_PAYMENT' && $appointment->order_status === 'UNPAID' && config('mediqueue.mock_payments_enabled') && app()->environment(['local', 'demo', 'testing']))
                <div class="surface-muted mt-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-slate-700">服务端计算的剩余时间</span>
                        <span class="text-lg font-bold text-[#13758a]">{{ $remaining }} 秒</span>
                    </div>
                    <p class="mt-2 text-xs leading-6 text-slate-500">以上是页面加载时的剩余时间；最终支付资格由服务端再次判断。结果未知时请先返回预约详情核实。</p>
                </div>
                <form class="mt-6" method="POST" action="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('appointments.pay', now()->addMinutes(5), ['id' => $appointment->id]) }}" data-submit-once>
                    @csrf
                    <label class="field">选择演示结果
                        <select name="scenario">
                            <option value="success">成功</option>
                            <option value="declined">拒绝</option>
                            <option value="unknown">结果未知</option>
                        </select>
                    </label>
                    <button class="btn mt-5 w-full" data-label="确认模拟支付" type="submit">确认模拟支付 →</button>
                </form>
            @else
                <p class="mt-6 rounded-xl bg-slate-100 p-4 text-sm text-slate-600">此订单当前不能发起模拟支付，请返回预约详情查看状态。</p>
            @endif

            <p class="mt-6 border-t border-slate-100 pt-5 text-center text-xs leading-6 text-slate-500">模拟微信支付／无实际扣款 · 无真实资金交易、微信商户接入或支付二维码。</p>
        </section>
    </div>
@endsection
