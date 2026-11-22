@extends('layouts.app')
@section('title', '本人排班详情')
@section('content')
    @php($zone = config('mediqueue.hospital_timezone'))
    <a class="text-link back-link" href="{{ route('doctor.workbench') }}">返回医生工作台</a>
    <div class="section-head mt-2">
        <h1 class="page-title">{{ $doctor->full_name }} <span class="num">{{ $schedule->work_date }}</span> 出诊</h1>
        <x-resource-status :value="$schedule->status" />
    </div>
    @if($schedule->suspend_reason)<p class="alert-error mt-4">停诊说明：{{ $schedule->suspend_reason }}</p>@endif
    <p class="hint mt-4">时间均为北京时间。</p>

    <section class="mt-6">
        <h2 class="section-title">出诊时段</h2>
        <div class="table-wrap mt-2" tabindex="0" role="region" aria-label="本人出诊时段">
            <table>
                <thead><tr><th>时段</th><th class="col-num">已占 / 容量</th><th class="col-num">挂号费</th><th>时段状态</th></tr></thead>
                <tbody>
                    @forelse($slots as $slot)
                        <tr>
                            <td><span class="cell-time">{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone($zone)->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone($zone)->format('H:i') }}</span></td>
                            <td class="col-num"><span class="num">{{ $slot->occupied_count }} / {{ $slot->capacity }}</span></td>
                            <td class="col-num"><span class="num">¥{{ $slot->fee_amount }}</span></td>
                            <td><x-resource-status :value="$slot->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="4">该排班尚未配置时段。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="mt-8">
        <h2 class="section-title">预约名单</h2>
        <p class="hint mt-1">仅列已确认或已完成的预约。</p>
        @forelse($patients->getCollection()->groupBy('starts_at') as $start => $roster)
            <h3 class="period-title"><span class="num">{{ \Carbon\CarbonImmutable::parse($start, 'UTC')->setTimezone($zone)->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($roster->first()->ends_at, 'UTC')->setTimezone($zone)->format('H:i') }}</span></h3>
            <div class="table-wrap" tabindex="0" role="region" aria-label="该时段预约名单">
                <table>
                    <thead><tr><th>就诊人</th><th>预约编号</th><th>预约状态</th></tr></thead>
                    <tbody>
                        @foreach($roster as $patient)
                            <tr data-status="{{ $patient->status }}"><td><span class="cell-main">{{ $patient->patient_name }}</span></td><td><span class="num">{{ $patient->appointment_no }}</span></td><td><x-resource-status :value="$patient->status" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="empty-state mt-3">暂无已确认或已完成的预约。</p>
        @endforelse
        <div class="mt-6">{{ $patients->links() }}</div>
    </section>
@endsection
