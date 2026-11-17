@extends('layouts.app')
@section('title', '本人排班详情')
@section('content')
    <a class="text-link" href="{{ route('doctor.workbench') }}">← 返回本人工作台</a>
    <h1 class="page-title mt-5">{{ $doctor->full_name }} · {{ $schedule->work_date }}</h1>
    <p class="mt-4"><x-resource-status :value="$schedule->status" /></p>
    @if($schedule->suspend_reason)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-amber-900">停诊说明：{{ $schedule->suspend_reason }}</p>@endif
    <div class="table-wrap mt-7"><table><thead><tr><th>北京时间</th><th>容量</th><th>已占用</th><th>挂号费</th><th>时段状态</th></tr></thead><tbody>
        @forelse($slots as $slot)<tr><td>{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</td><td>{{ $slot->capacity }}</td><td>{{ $slot->occupied_count }}</td><td>¥{{ $slot->fee_amount }}</td><td><x-resource-status :value="$slot->status" /></td></tr>
        @empty<tr><td colspan="5">该排班尚未配置时段。</td></tr>@endforelse
    </tbody></table></div>
    <p class="mt-5 text-sm text-slate-500">只读安排。本阶段尚未开放预约名单或财务信息。</p>
@endsection
