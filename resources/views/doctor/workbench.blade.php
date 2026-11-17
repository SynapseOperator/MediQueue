@extends('layouts.app')
@section('title', '医生工作台')
@section('content')
    <p class="eyebrow">本人出诊 · 只读</p><h1 class="page-title mt-2">{{ $doctor->full_name }}的工作台</h1>
    <p class="mt-3 text-slate-600">排班由管理员维护。如需调整，请联系管理员。此处不提供排班或号源修改入口。</p>
    @if($doctor->status !== 'ACTIVE')<p class="mt-5 rounded-xl bg-amber-50 p-4 text-amber-900">您的医生资源当前已停用，历史安排仍保留可查。</p>@endif
    <div class="table-wrap mt-8"><table><thead><tr><th>日期与时间</th><th>出诊科室</th><th>状态</th><th>详情</th></tr></thead><tbody>
        @forelse($schedules as $schedule)<tr><td>{{ $schedule->work_date }}<br><span class="text-slate-500">{{ \Carbon\CarbonImmutable::parse($schedule->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($schedule->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</span></td><td>{{ $schedule->department_name }}</td><td><x-resource-status :value="$schedule->status" /></td><td><a class="text-link" href="{{ route('doctor.schedules.show', $schedule->id) }}">查看时段</a></td></tr>
        @empty<tr><td colspan="4">暂无本人排班。</td></tr>@endforelse
    </tbody></table></div>
    <div class="mt-6">{{ $schedules->links() }}</div>
@endsection
