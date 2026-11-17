@extends('layouts.app')
@section('title', $detail ? '统计分析' : '医院概览')
@section('content')
    <p class="eyebrow">管理工作台 · 模拟数据</p>
    <h1 class="page-title">{{ $detail ? '统计分析' : '医院概览' }}</h1>
    <p class="page-intro">按明确日期口径核对预约与名额。所有金额均为模拟微信支付，无实际扣款，不等于真实收入。</p>
    <form method="GET" class="panel mt-6 flex flex-wrap items-end gap-4">
        <label class="field">开始日期（上海）<input type="date" name="from" value="{{ $from }}" required></label>
        <label class="field">结束日期（含当日）<input type="date" name="to" value="{{ $to }}" required></label>
        <button class="btn" type="submit">查看统计</button>
        <a class="text-link" href="{{ route($detail ? 'admin.dashboard' : 'admin.analytics', ['from' => $from, 'to' => $to]) }}">{{ $detail ? '返回概览' : '查看科室明细' }}</a>
    </form>
    @php
        $cards = [
            ['新建预约', $summary->created_count, '按预约创建日期；包括后来取消或过期的记录'],
            ['当前已确认', $summary->confirmed_count, '按就诊日期；当前 CONFIRMED，不含已完成'],
            ['已完成就诊', $summary->completed_count, '按就诊日期；仅人工登记 COMPLETED'],
            ['名额占用率', $summary->occupancy_mismatches ? '待核对' : ($summary->capacity > 0 ? number_format($summary->occupied_count * 100 / $summary->capacity, 1).'%' : '—'), '按就诊日期；账实一致后展示，含待回收、已确认、已完成'],
            ['已支付有效预约', $summary->paid_valid_count, '按就诊日期；CONFIRMED + COMPLETED'],
            ['创建队列取消率', $summary->created_count > 0 ? number_format($summary->cohort_cancelled * 100 / $summary->created_count, 1).'%' : '—', '同一创建日期队列内 CANCELLED / 全部新建'],
            ['模拟付款金额', '¥'.$summary->simulated_paid, '按 paid_at 日期；包括之后取消或退款的订单'],
            ['模拟成功退款', '¥'.$summary->simulated_refunded, '按 succeeded_at 日期；不含待处理和失败'],
            ['模拟净额', '¥'.$summary->simulated_net, '本期模拟付款减本期成功退款；可能为负'],
        ];
    @endphp
    <section class="mt-8" aria-label="九项统计指标">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="section-title">关键运营指标</h2>
            <p class="text-xs text-slate-500">按所选日期范围计算 · 坚持区分预约、就诊与模拟账务</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($cards as [$label, $value, $meaning])
                <article class="metric-card {{ $loop->first ? 'metric-card--lead' : '' }} {{ $loop->index >= 6 ? 'metric-card--finance' : '' }} {{ $loop->index > 2 ? 'metric-card--compact' : '' }}">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="metric-label">{{ $label }}</h3>
                        <span class="metric-index" aria-hidden="true">{{ str_pad((string) ($loop->iteration), 2, '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <p class="metric-number">{{ $value }}</p>
                    <p class="metric-note">{{ $meaning }}</p>
                </article>
            @endforeach
        </div>
    </section>
    <p class="mt-4 text-sm text-slate-600">所选就诊日期容量 {{ $summary->capacity }}，存储占用 {{ $summary->occupied_count }}；单 SQL 账实差异号源 {{ $summary->occupancy_mismatches }} 个。{{ $summary->occupancy_mismatches ? '存在差异，请调查；本页面不会修正计数。' : '当前核对一致。' }} 分母为零的比率显示“—”。</p>
    <section class="mt-8 grid gap-5 lg:grid-cols-3" aria-label="待处理事项">
        <article class="panel"><h2 class="section-title">待退款 {{ $summary->pending_refunds }}</h2><p class="mt-2 text-sm text-slate-500">全库当前待处理或失败，不受日期过滤；展示最早 5 条。</p>
            @forelse($pendingRefunds as $item)<p class="mt-3"><a class="text-link" href="{{ route('admin.appointments.show', $item->appointment_id) }}">{{ $item->appointment_no }}</a><br><x-resource-status :value="$item->refund_status" /> <span class="text-sm">模拟 ¥{{ $item->refund_amount }}</span></p>@empty<p class="mt-4 text-slate-500">暂无待退款事项。</p>@endforelse
        </article>
        <article class="panel"><h2 class="section-title">停诊待处理 {{ $summary->suspended_count }}</h2><p class="mt-2 text-sm text-slate-500">所选就诊日期，SUSPENDED 排班的待支付/已确认预约；最早 5 条。</p>
            @forelse($suspended as $item)<p class="mt-3"><a class="text-link" href="{{ route('admin.appointments.show', $item->appointment_id) }}">{{ $item->appointment_no }}</a><br><span class="text-sm">{{ $item->department_name }}</span> <x-resource-status :value="$item->appointment_status" /></p>@empty<p class="mt-4 text-slate-500">暂无停诊待处理事项。</p>@endforelse
        </article>
        <article class="panel"><h2 class="section-title">就诊结果待核实 {{ $summary->unverified_count }}</h2><p class="mt-2 text-sm text-slate-500">所选就诊日期，时段已结束但仍 CONFIRMED；最早 5 条，不自动完成。</p>
            @forelse($unverified as $item)<p class="mt-3"><a class="text-link" href="{{ route('admin.appointments.show', $item->appointment_id) }}">{{ $item->appointment_no }}</a><br><span class="text-sm">{{ $item->department_name }}</span></p>@empty<p class="mt-4 text-slate-500">暂无待核实事项。</p>@endforelse
        </article>
    </section>
    @if($detail)
        <section class="panel mt-8"><h2 class="section-title">预约创建数量变化</h2><p class="mt-2 text-slate-600">按上海创建日期分组，包含后来取消或过期的预约。仅列有新建记录的日期，未列日期为零；条形与本期单日最高值比较。</p>
            @if($creationTrend->isEmpty())
                <p class="mt-5 text-slate-500">所选日期没有新建预约，暂无创建趋势。</p>
            @else
                @php($peak = $creationTrend->max('appointment_count'))
                <div class="table-wrap mt-5" tabindex="0" role="region" aria-label="按上海创建日期统计的预约数量"><table><thead><tr><th>创建日期</th><th>新建预约</th><th>相对单日峰值</th></tr></thead><tbody>
                    @foreach($creationTrend as $day)<tr><td>{{ $day->creation_date }}</td><td>{{ $day->appointment_count }}</td><td><svg width="180" height="12" viewBox="0 0 180 12" aria-hidden="true"><rect width="180" height="12" rx="6" fill="#e2e8f0"/><rect width="{{ $day->appointment_count * 180 / $peak }}" height="12" rx="6" fill="#0f766e"/></svg></td></tr>@endforeach
                </tbody></table></div>
            @endif
        </section>
        <section class="panel mt-8"><h2 class="section-title">按出诊科室核对</h2><p class="mt-2 text-slate-600">按 schedules.work_date 与该排班历史科室。容量先按号源聚合，预约另行聚合；空科室显示 0。条形以 0–100% 展示占用率。</p>
            <div class="table-wrap mt-5" tabindex="0" role="region" aria-label="科室统计，可横向滚动"><table><thead><tr><th>科室</th><th>预约总量</th><th>容量 / 占用</th><th>占用率</th><th>待支付</th><th>已确认</th><th>已完成</th><th>已取消</th><th>已过期</th></tr></thead><tbody>
            @forelse($departments as $department)
                <tr><td>{{ $department->department_name }}</td><td>{{ $department->pending_payment + $department->confirmed + $department->completed + $department->cancelled + $department->expired }}</td><td>{{ $department->capacity }} / {{ $department->occupied_count }}</td><td>
                    @if($summary->occupancy_mismatches)
                        待核对
                    @elseif($department->capacity > 0)
                        @php($percentage = $department->occupied_count * 100 / $department->capacity)
                        <span>{{ number_format($percentage, 1) }}%</span><svg width="120" height="8" viewBox="0 0 120 8" aria-hidden="true"><rect width="120" height="8" rx="4" fill="#e2e8f0"/><rect width="{{ min(120, max(0, $percentage * 1.2)) }}" height="8" rx="4" fill="#0f766e"/></svg>
                    @else — @endif
                </td><td>{{ $department->pending_payment }}</td><td>{{ $department->confirmed }}</td><td>{{ $department->completed }}</td><td>{{ $department->cancelled }}</td><td>{{ $department->expired }}</td></tr>
            @empty<tr><td colspan="9">暂无科室数据。</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif
@endsection
