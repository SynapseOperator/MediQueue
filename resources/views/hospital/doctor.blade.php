@extends('layouts.app')
@section('title', $doctor->full_name)
@section('content')
    <a class="text-link" href="{{ route('doctors.index') }}">← 返回医生列表</a>
    <section class="panel mt-6"><p class="eyebrow">{{ $doctor->department_name }}</p><h1 class="page-title mt-2">{{ $doctor->full_name }} <span class="text-lg font-normal text-slate-500">{{ $doctor->title }}</span></h1><p class="mt-4 font-medium">擅长：{{ $doctor->specialty ?: '暂未填写' }}</p><p class="mt-4 whitespace-pre-line leading-7 text-slate-600">{{ $doctor->introduction ?: '医生暂未提供详细介绍。' }}</p></section>
    <section class="mt-9">
        <h2 class="text-2xl font-semibold">未来出诊时段</h2><p class="mt-3 text-slate-600">仅显示未来 {{ config('mediqueue.availability_days') }} 个自然日内开放且尚未开始的时段。患者可提交预约；付款将在下一阶段开放。</p>
        <form method="GET" class="mt-5 flex flex-wrap items-end gap-3">
            <label class="field">日期<input type="date" name="date" value="{{ request('date') }}" min="{{ $today->toDateString() }}" max="{{ $lastDay->toDateString() }}"></label><button class="btn" type="submit">筛选</button><a class="btn-secondary" href="{{ route('doctors.show', $doctor->id) }}">全部日期</a>
        </form>
        <p class="mt-5 text-xs text-slate-500 sm:hidden">左右滑动下方表格，可查看完整时段与名额 →</p>
        <div class="table-wrap mt-3" tabindex="0" role="region" aria-label="出诊时段，可横向滚动"><table><thead><tr><th>出诊日期</th><th>北京时间</th><th>出诊科室</th><th>挂号费</th><th>名额</th><th>预约</th></tr></thead><tbody>
            @forelse($slots as $slot)
                <tr><td>{{ $slot->work_date }}</td><td>{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</td><td>{{ $slot->department_name }}</td><td>¥{{ $slot->fee_amount }}</td><td>{{ $slot->capacity - $slot->occupied_count > 0 ? '剩余 '.($slot->capacity - $slot->occupied_count).' / '.$slot->capacity : '已满' }}</td><td>
                    @auth
                        @if(auth()->user()->role === 'PATIENT' && $slot->capacity > $slot->occupied_count)
                            <form method="POST" action="{{ route('appointments.store') }}">@csrf
                                <input type="hidden" name="slot_id" value="{{ $slot->id }}">
                                <input type="hidden" name="request_key" value="{{ ($oldInput['slot_id'] ?? null) == $slot->id && isset($oldInput['request_key']) ? $oldInput['request_key'] : (string) \Illuminate\Support\Str::uuid() }}">
                                <button class="btn" type="submit">预约</button>
                            </form>
                        @endif
                    @else<a class="text-link" href="{{ route('login') }}">登录后预约</a>@endauth
                </td></tr>
            @empty<tr><td colspan="6" class="text-slate-500">暂无可展示的出诊时段，可能尚未发布、已经开始或已停诊。</td></tr>@endforelse
        </tbody></table></div>
        <div class="mt-6">{{ $slots->links() }}</div>
    </section>
@endsection
