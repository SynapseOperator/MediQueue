@extends('layouts.app')
@section('title', '我的预约')
@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">MY APPOINTMENTS · 患者中心</p>
            <h1 class="page-title mt-2">我的预约</h1>
            <p class="page-intro">查看预约、订单与模拟退款进度。提交结果不确定时，请先在这里核实。</p>
        </div>
        <a class="btn-secondary" href="{{ route('doctors.index') }}">预约新的时段 →</a>
    </div>

    <form class="panel mt-7 flex flex-wrap items-end gap-3" method="GET" action="{{ route('appointments.index') }}">
        <label class="field w-full sm:min-w-56 sm:w-auto">预约状态
            <select name="status">
                <option value="">全部状态</option>
                @foreach(['PENDING_PAYMENT'=>'待支付','CONFIRMED'=>'已确认','CANCELLED'=>'已取消','EXPIRED'=>'已过期','COMPLETED'=>'已完成'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn w-full sm:w-auto" type="submit">筛选记录</button>
        <a class="text-link px-2 py-3 text-sm" href="{{ route('appointments.index') }}">清除筛选</a>
    </form>

    <div class="mt-7 space-y-4">
        @forelse($appointments as $appointment)
            @php($visit = \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone')))
            @php($visitEnd = \Carbon\CarbonImmutable::parse($appointment->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone')))
            <article class="panel">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                    <div class="flex shrink-0 items-center gap-3 rounded-2xl bg-[#ecf6f9] px-4 py-3 sm:w-28 sm:flex-col sm:gap-0 sm:text-center">
                        <span class="text-sm font-semibold text-[#2d6e83]">{{ $visit->format('Y-m') }}</span>
                        <span class="text-2xl font-bold tracking-tight text-[#145f77] sm:mt-1 sm:text-3xl">{{ $visit->format('d') }}</span>
                        <span class="text-sm font-semibold text-[#3b7486] sm:mt-1">{{ $visit->format('H:i') }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="eyebrow">{{ $appointment->department_name }}</p>
                        <h2 class="mt-2 text-xl font-bold text-slate-800">{{ $appointment->doctor_name }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">出诊时间（北京时间）{{ $visit->format('Y-m-d H:i') }}–{{ $visitEnd->format('H:i') }}</p>
                        <p class="mt-1 text-sm text-slate-500">挂号费 ¥{{ $appointment->fee_amount }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-resource-status :value="$appointment->status" />
                            <x-resource-status :value="$appointment->order_status" />
                            @if($appointment->refund_status)<x-resource-status :value="$appointment->refund_status" />@endif
                        </div>
                    </div>
                    <a class="btn-secondary w-full shrink-0 sm:w-auto" href="{{ route('appointments.show', $appointment->id) }}">查看详情 →</a>
                </div>
                <div class="mt-5 border-t border-slate-100 pt-4 text-xs text-slate-400">预约编号：<span class="break-all">{{ $appointment->appointment_no }}</span></div>
            </article>
        @empty
            <div class="empty-state">
                <h2 class="text-lg font-bold text-slate-700">暂无符合条件的预约</h2>
                <p class="mt-2 text-sm">试试其他状态，或浏览医生选择时段。</p>
                <a class="btn-secondary mt-5" href="{{ route('doctors.index') }}">查找医生</a>
            </div>
        @endforelse
    </div>
    <div class="mt-7">{{ $appointments->links() }}</div>
@endsection
