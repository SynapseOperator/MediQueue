@extends('layouts.app')
@section('title', '医生工作台')
@section('content')
    @php($zone = config('mediqueue.hospital_timezone'))
    <h1 class="page-title">{{ $doctor->full_name }}的工作台</h1>
    <form method="GET" class="filter-bar mt-6">
        <label class="field">出诊日期<input type="date" name="date" value="{{ request('date') }}"></label>
        <button class="btn-secondary" type="submit">筛选</button>
        <a class="text-link" href="{{ route('doctor.workbench') }}">全部日期</a>
    </form>
    @if($doctor->status !== 'ACTIVE')
        <p class="alert-error mt-4">您已暂停接诊，仍可查看既往出诊安排。</p>
    @endif
    <p class="hint mt-6">时间均为北京时间。</p>
    @forelse($schedules->getCollection()->groupBy('work_date') as $date => $day)
        <section class="mt-6">
            <h2 class="section-title"><span class="num">{{ $date }}</span> <span class="hint">{{ \Carbon\CarbonImmutable::parse($date)->locale('zh_CN')->isoFormat('dddd') }}</span></h2>
            <div class="table-wrap mt-2" tabindex="0" role="region" aria-label="{{ $date }} 出诊安排">
                <table>
                    <thead><tr><th>出诊时间</th><th>出诊科室</th><th>排班状态</th><th class="text-right">名单</th></tr></thead>
                    <tbody>
                        @foreach($day as $schedule)
                            <tr>
                                <td><span class="cell-time">{{ \Carbon\CarbonImmutable::parse($schedule->starts_at, 'UTC')->setTimezone($zone)->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($schedule->ends_at, 'UTC')->setTimezone($zone)->format('H:i') }}</span></td>
                                <td>{{ $schedule->department_name }}</td>
                                <td><x-resource-status :value="$schedule->status" /></td>
                                <td class="text-right"><a class="btn-secondary btn-sm" href="{{ route('doctor.schedules.show', $schedule->id) }}">时段及名单</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <p class="empty-state mt-4">暂无本人排班。</p>
    @endforelse
    <div class="mt-6">{{ $schedules->links() }}</div>
@endsection
