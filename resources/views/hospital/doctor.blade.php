@extends('layouts.app')
@section('title', $doctor->full_name)
@section('content')
    <a class="text-link text-sm" href="{{ route('doctors.index') }}">← 返回医生列表</a>

    <section class="panel mt-5">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
            <div class="doctor-avatar doctor-avatar--large" aria-hidden="true">医</div>
            <div class="min-w-0 flex-1">
                <p class="eyebrow">{{ $doctor->department_name }} · 出诊医生</p>
                <div class="mt-2 flex flex-wrap items-baseline gap-3">
                    <h1 class="page-title">{{ $doctor->full_name }}</h1>
                    <span class="rounded-full bg-[#edf5fa] px-3 py-1 text-sm font-semibold text-[#245f76]">{{ $doctor->title }}</span>
                </div>
                @if($doctor->specialty)
                    <p class="mt-4 text-sm font-semibold text-slate-700">擅长：{{ $doctor->specialty }}</p>
                @else
                    <p class="mt-4 text-sm text-slate-500">擅长：暂未填写</p>
                @endif
                <p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-600">{{ $doctor->introduction ?: '医生暂未提供详细介绍。您可以先查看出诊时段。' }}</p>
            </div>
            <a class="btn-secondary shrink-0" href="#available-slots">查看可预约时段 ↓</a>
        </div>
    </section>

    <section id="available-slots" class="mt-10 scroll-mt-28">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow">AVAILABLE APPOINTMENTS · 近期出诊</p>
                <h2 class="section-title mt-2">未来出诊时段</h2>
                <p class="page-intro">查看未来 {{ config('mediqueue.availability_days') }} 个自然日开放的时段，选择合适时间后核对预约信息。</p>
            </div>
            <span class="rounded-full bg-[#e7f4f7] px-3 py-1.5 text-xs font-bold text-[#196c80]">北京时间 · Asia/Shanghai</span>
        </div>

        <form method="GET" class="panel mt-6 flex flex-wrap items-end gap-3">
            <label class="field w-full flex-1 sm:w-auto sm:min-w-52">按日期筛选
                <input type="date" name="date" value="{{ request('date') }}" min="{{ $today->toDateString() }}" max="{{ $lastDay->toDateString() }}">
            </label>
            <button class="btn w-full sm:w-auto" type="submit">筛选</button>
            <a class="btn-secondary w-full sm:w-auto" href="{{ route('doctors.show', $doctor->id) }}">全部日期</a>
        </form>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-slate-700">时段列表</h3>
            <p class="text-xs text-slate-500">当前显示 {{ $slots->count() }} 个时段 · 名额实时以服务端为准</p>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse($slots as $slot)
                @php
                    $remaining = max(0, $slot->capacity - $slot->occupied_count);
                    $startTime = \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'));
                    $endTime = \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'));
                @endphp
                <article class="booking-slot {{ $remaining === 0 ? 'is-full' : '' }}">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="slot-date">{{ $slot->work_date }} · {{ $startTime->locale('zh_CN')->isoFormat('dddd') }}</p>
                            <p class="slot-hour mt-2">{{ $startTime->format('H:i') }} <span class="text-sm font-normal text-slate-400">—</span> {{ $endTime->format('H:i') }}</p>
                        </div>
                        <span class="slot-capacity {{ $remaining === 0 ? 'is-full' : '' }}">{{ $remaining > 0 ? '剩余 '.$remaining.' / '.$slot->capacity : '已满' }}</span>
                    </div>
                    <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                        <div>
                            <p class="text-xs text-slate-500">{{ $slot->department_name }} · 挂号费</p>
                            <p class="mt-1 text-lg font-bold text-slate-800">¥{{ $slot->fee_amount }}</p>
                        </div>
                        @if($remaining === 0)
                            <span class="rounded-xl bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-500">已约满</span>
                        @else
                            @auth
                                @if(auth()->user()->role === 'PATIENT')
                                    @php($oldKey = old('request_key'))
                                    <form method="GET" action="{{ route('appointments.confirm') }}">
                                        <input type="hidden" name="slot_id" value="{{ $slot->id }}">
                                        <input type="hidden" name="request_key" value="{{ old('slot_id') == $slot->id && is_string($oldKey) && preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $oldKey) ? $oldKey : (string) \Illuminate\Support\Str::uuid() }}">
                                        <button class="btn" type="submit">选择时段 →</button>
                                    </form>
                                @else
                                    <span class="hint">仅患者可预约</span>
                                @endif
                            @else
                                <a class="btn-secondary" href="{{ route('login') }}">登录后预约</a>
                            @endauth
                        @endif
                    </div>
                </article>
            @empty
                <div class="empty-state col-span-full">
                    <p class="text-lg font-bold text-slate-700">当前日期暂无可预约时段</p>
                    <p class="mt-2 text-sm">可能尚未发布、已经开始或已停诊。试试其他日期或医生。</p>
                    <a class="btn-secondary mt-5" href="{{ route('doctors.index') }}">选择其他医生</a>
                </div>
            @endforelse
        </div>
        <div class="mt-7">{{ $slots->links() }}</div>
        <p class="mt-3 text-xs leading-6 text-slate-500">模拟微信支付仅用于课程演示，无实际扣款。当前页面展示的剩余名额可能因其他患者同时预约而变化。</p>
    </section>
@endsection
