@extends('layouts.app')
@section('title', '本人排班详情')
@section('content')
    <a class="text-link" href="{{ route('doctor.workbench') }}">返回医生工作台</a>
    <h1 class="page-title mt-4">{{ $doctor->full_name }} · {{ $schedule->work_date }}</h1>
    <div class="mt-2"><x-resource-status :value="$schedule->status" /></div>
    @if($schedule->suspend_reason)<p class="mt-3 text-amber-900">停诊说明：{{ $schedule->suspend_reason }}</p>@endif

    <section class="mt-6">
        <h2 class="section-title">出诊时段</h2>
        <div class="table-wrap mt-2" tabindex="0" role="region" aria-label="本人出诊时段">
            <table>
                <thead><tr><th>北京时间</th><th>容量</th><th>已占</th><th>挂号费</th><th>时段状态</th></tr></thead>
                <tbody>
                    @forelse($slots as $slot)
                        <tr>
                            <td>{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</td>
                            <td>{{ $slot->capacity }}</td><td>{{ $slot->occupied_count }}</td>
                            <td>¥{{ $slot->fee_amount }}</td><td><x-resource-status :value="$slot->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">该排班尚未配置时段。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="mt-6">
        <h2 class="section-title">预约名单</h2>
        <p class="hint mt-2">仅列已确认或已完成的预约。</p>
        @forelse($patients->getCollection()->groupBy('starts_at') as $start => $roster)
            <h3 class="mt-4 font-bold">{{ \Carbon\CarbonImmutable::parse($start, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($roster->first()->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</h3>
            <div class="table-wrap mt-2" tabindex="0" role="region" aria-label="该时段预约名单">
                <table>
                    <thead><tr><th>就诊人</th><th>预约编号</th><th>预约状态</th></tr></thead>
                    <tbody>
                        @foreach($roster as $patient)
                            <tr><td>{{ $patient->patient_name }}</td><td>{{ $patient->appointment_no }}</td><td><x-resource-status :value="$patient->status" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="empty-state mt-3">暂无已确认或已完成的预约。</p>
        @endforelse
        <div class="mt-5">{{ $patients->links() }}</div>
    </section>
@endsection
