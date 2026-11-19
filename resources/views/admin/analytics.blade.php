@extends('layouts.app')
@section('title', $detail ? '统计分析' : '医院概览')
@section('content')
    <h1 class="page-title">{{ $detail ? '统计分析' : '医院概览' }}</h1>
    <form method="GET" class="panel mt-6 flex flex-wrap items-end gap-4">
        <label class="field">开始日期（北京时间）<input type="date" name="from" value="{{ $from }}" required></label>
        <label class="field">结束日期（含当日）<input type="date" name="to" value="{{ $to }}" required></label>
        <button class="btn" type="submit">查看统计</button>
        <a class="text-link" href="{{ route($detail ? 'admin.dashboard' : 'admin.analytics', ['from' => $from, 'to' => $to]) }}">{{ $detail ? '返回概览' : '查看科室明细' }}</a>
    </form>
    @php
        $cards = [
            ['新建预约', $summary->created_count, '按预约创建日期统计，包括后来取消或过期的预约。'],
            ['当前已确认', $summary->confirmed_count, '按就诊日期统计当前已确认且尚未登记完成的预约。'],
            ['已完成就诊', $summary->completed_count, '按就诊日期统计已人工登记完成的预约。'],
            ['名额占用率', $summary->occupancy_mismatches ? '待核对' : ($summary->capacity > 0 ? number_format($summary->occupied_count * 100 / $summary->capacity, 1).'%' : '—'), '按就诊日期，以已占名额除以总容量。包括待支付、已确认和已完成的预约。'],
            ['已支付有效预约', $summary->paid_valid_count, '按就诊日期统计已确认及已完成的预约。'],
            ['创建队列取消率', $summary->created_count > 0 ? number_format($summary->cohort_cancelled * 100 / $summary->created_count, 1).'%' : '—', '在本期创建的预约中，已取消数占新建总数的比例。'],
            ['模拟付款金额', '¥'.$summary->simulated_paid, '按付款日期统计，包括之后取消或退款的订单。'],
            ['模拟成功退款', '¥'.$summary->simulated_refunded, '按退款成功日期统计，不含待处理及失败的退款。'],
            ['模拟净额', '¥'.$summary->simulated_net, '本期模拟付款金额减去本期成功退款金额，可能为负。'],
        ];
    @endphp
    <section class="mt-6" aria-label="运营指标">
        <h2 class="section-title">运营指标</h2>
        <div class="metric-grid mt-3">
            @foreach($cards as [$label, $value, $meaning])
                <article class="metric-card">
                    <div class="flex items-start gap-2">
                        <h3 class="metric-label">{{ $label }}</h3>
                        <details class="min-w-0 text-xs">
                            <summary class="cursor-pointer text-gray-700" aria-label="{{ $label }}的统计口径">?</summary>
                            <p class="hint mt-1">{{ $meaning }}</p>
                        </details>
                    </div>
                    <p class="metric-number">{{ $value }}</p>
                </article>
            @endforeach
        </div>
    </section>
    <p class="hint mt-3">所选就诊日期共 {{ $summary->capacity }} 个名额，已占 {{ $summary->occupied_count }} 个。
        @if($summary->occupancy_mismatches)有 {{ $summary->occupancy_mismatches }} 个时段的占用数与预约记录不一致，请核对。@endif
        分母为零的比率显示“—”。
    </p>
    <section class="mt-8 grid gap-5 lg:grid-cols-3" aria-label="待处理事项">
        <article class="panel"><h2 class="section-title">待退款 {{ $summary->pending_refunds }}</h2><p class="mt-2 text-sm text-slate-500">列出最早的 5 条待处理或失败退款，含所选日期之外的记录。</p>
            @forelse($pendingRefunds as $item)<p class="mt-3"><a class="text-link" href="{{ route('admin.appointments.show', $item->appointment_id) }}">{{ $item->appointment_no }}</a><br><x-resource-status :value="$item->refund_status" /> <span class="text-sm">模拟 ¥{{ $item->refund_amount }}</span></p>@empty<p class="mt-4 text-slate-500">暂无待退款事项。</p>@endforelse
        </article>
        <article class="panel"><h2 class="section-title">停诊待处理 {{ $summary->suspended_count }}</h2><p class="mt-2 text-sm text-slate-500">所选就诊日期内，停诊排班仍待支付或已确认的预约，按时间列出最早 5 条。</p>
            @forelse($suspended as $item)<p class="mt-3"><a class="text-link" href="{{ route('admin.appointments.show', $item->appointment_id) }}">{{ $item->appointment_no }}</a><br><span class="text-sm">{{ $item->department_name }}</span> <x-resource-status :value="$item->appointment_status" /></p>@empty<p class="mt-4 text-slate-500">暂无停诊待处理事项。</p>@endforelse
        </article>
        <article class="panel"><h2 class="section-title">就诊结果待核实 {{ $summary->unverified_count }}</h2><p class="mt-2 text-sm text-slate-500">所选就诊日期内，时段已结束且仍为已确认的预约，按时间列出最早 5 条。</p>
            @forelse($unverified as $item)<p class="mt-3"><a class="text-link" href="{{ route('admin.appointments.show', $item->appointment_id) }}">{{ $item->appointment_no }}</a><br><span class="text-sm">{{ $item->department_name }}</span></p>@empty<p class="mt-4 text-slate-500">暂无待核实事项。</p>@endforelse
        </article>
    </section>
    @if($detail)
        <section class="panel mt-8"><h2 class="section-title">预约创建数量变化</h2><p class="mt-2 text-slate-600">按北京时间统计新建预约，包括后来取消或过期的预约。未列日期为零，条形表示与本期单日最高值的比例。</p>
            @if($creationTrend->isEmpty())
                <p class="mt-5 text-slate-500">所选日期没有新建预约，暂无创建趋势。</p>
            @else
                @php($peak = $creationTrend->max('appointment_count'))
                <div class="table-wrap mt-5" tabindex="0" role="region" aria-label="按创建日期统计的预约数量"><table><thead><tr><th>创建日期</th><th>新建预约</th><th>相对单日峰值</th></tr></thead><tbody>
                    @foreach($creationTrend as $day)<tr><td>{{ $day->creation_date }}</td><td>{{ $day->appointment_count }}</td><td><svg width="180" height="12" viewBox="0 0 180 12" aria-hidden="true"><rect width="180" height="12" rx="6" fill="#e2e8f0"/><rect width="{{ $day->appointment_count * 180 / $peak }}" height="12" rx="6" fill="#0f6e84"/></svg></td></tr>@endforeach
                </tbody></table></div>
            @endif
        </section>
        <section class="panel mt-8"><h2 class="section-title">按出诊科室核对</h2><p class="mt-2 text-slate-600">按就诊日期及出诊时的科室统计。占用率为已占名额占科室总容量的比例。</p>
            <div class="table-wrap mt-5" tabindex="0" role="region" aria-label="科室统计，可横向滚动"><table><thead><tr><th>科室</th><th>预约总量</th><th>容量 / 占用</th><th>占用率</th><th>待支付</th><th>已确认</th><th>已完成</th><th>已取消</th><th>已过期</th></tr></thead><tbody>
            @forelse($departments as $department)
                <tr><td>{{ $department->department_name }}</td><td>{{ $department->pending_payment + $department->confirmed + $department->completed + $department->cancelled + $department->expired }}</td><td>{{ $department->capacity }} / {{ $department->occupied_count }}</td><td>
                    @if($summary->occupancy_mismatches)
                        待核对
                    @elseif($department->capacity > 0)
                        @php($percentage = $department->occupied_count * 100 / $department->capacity)
                        <span>{{ number_format($percentage, 1) }}%</span><svg width="120" height="8" viewBox="0 0 120 8" aria-hidden="true"><rect width="120" height="8" rx="4" fill="#e2e8f0"/><rect width="{{ min(120, max(0, $percentage * 1.2)) }}" height="8" rx="4" fill="#0f6e84"/></svg>
                    @else — @endif
                </td><td>{{ $department->pending_payment }}</td><td>{{ $department->confirmed }}</td><td>{{ $department->completed }}</td><td>{{ $department->cancelled }}</td><td>{{ $department->expired }}</td></tr>
            @empty<tr><td colspan="9">暂无科室数据。</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif
@endsection
