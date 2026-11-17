@extends('layouts.app')
@section('title', '医生工作台')
@section('content')
    <p class="eyebrow">本人出诊 · 只读</p><h1 class="page-title mt-2">{{ $doctor->full_name }}的工作台</h1>
    <p class="mt-3 text-slate-600">排班由管理员维护。如需调整，请联系管理员。此处不提供排班或号源修改入口。</p>
    <form method="GET" class="surface-muted mt-6 flex flex-wrap items-end gap-3"><label class="field">出诊日期<input type="date" name="date" value="{{ request('date') }}"></label><button class="btn" type="submit">筛选</button><a class="text-link py-3" href="{{ route('doctor.workbench') }}">全部日期</a></form>
    @if($doctor->status !== 'ACTIVE')<p class="mt-5 rounded-xl bg-amber-50 p-4 text-amber-900">您的医生资源当前已停用，历史安排仍保留可查。</p>@endif
    <section class="mt-8"><h2 class="section-title">出诊时间轴</h2><div class="mt-5 space-y-4">
        @forelse($schedules as $schedule)<article class="panel flex flex-wrap items-start gap-5"><div class="min-w-32 border-l-4 border-teal-500 pl-4"><p class="font-bold">{{ $schedule->work_date }}</p><p class="mt-1 text-sm text-slate-500">{{ \Carbon\CarbonImmutable::parse($schedule->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($schedule->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</p></div><div class="flex-1"><p class="font-bold">{{ $schedule->department_name }}</p><div class="mt-2"><x-resource-status :value="$schedule->status" /></div></div><a class="text-link" href="{{ route('doctor.schedules.show', $schedule->id) }}">查看时段 →</a></article>
        @empty<div class="panel">暂无本人排班。</div>@endforelse
    </div></section>
    <div class="mt-6">{{ $schedules->links() }}</div>
@endsection
