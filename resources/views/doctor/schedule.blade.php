@extends('layouts.app')
@section('title', '本人排班详情')
@section('content')
    <a class="text-link" href="{{ route('doctor.workbench') }}">← 返回本人工作台</a>
    <h1 class="page-title mt-5">{{ $doctor->full_name }} · {{ $schedule->work_date }}</h1>
    <p class="mt-4"><x-resource-status :value="$schedule->status" /></p>
    @if($schedule->suspend_reason)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-amber-900">停诊说明：{{ $schedule->suspend_reason }}</p>@endif
    <p class="mt-7 text-xs text-slate-500 sm:hidden">左右滑动查看完整时段 →</p><div class="table-wrap mt-3" tabindex="0" role="region" aria-label="本人时段，可横向滚动"><table><thead><tr><th>北京时间</th><th>容量</th><th>已占用</th><th>挂号费</th><th>时段状态</th></tr></thead><tbody>
        @forelse($slots as $slot)<tr><td>{{ \Carbon\CarbonImmutable::parse($slot->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</td><td>{{ $slot->capacity }}</td><td>{{ $slot->occupied_count }}</td><td>¥{{ $slot->fee_amount }}</td><td><x-resource-status :value="$slot->status" /></td></tr>
        @empty<tr><td colspan="5">该排班尚未配置时段。</td></tr>@endforelse
    </tbody></table></div>
    <section class="mt-10"><p class="eyebrow">本人出诊 · 只读</p><h2 class="section-title mt-2">预约名单</h2><p class="mt-3 text-sm text-slate-600">仅显示本次排班已确认或已完成的预约，不展示患者联系方式及支付信息。</p><div class="mt-5 space-y-3">@forelse($patients as $patient)<article class="panel flex flex-wrap items-center justify-between gap-3"><div><p class="font-bold">{{ $patient->patient_name }}</p><p class="mt-1 text-sm text-slate-500">{{ \Carbon\CarbonImmutable::parse($patient->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($patient->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }} · {{ $patient->appointment_no }}</p></div><x-resource-status :value="$patient->status" /></article>@empty<p class="panel">暂无已确认或已完成的预约。</p>@endforelse</div><div class="mt-6">{{ $patients->links() }}</div></section>
@endsection
