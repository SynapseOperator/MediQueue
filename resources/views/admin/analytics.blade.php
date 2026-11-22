@extends('layouts.app')
@section('title', $detail ? '统计分析' : '医院概览')
@section('content')
    <h1 class="page-title">{{ $detail ? '统计分析' : '医院概览' }}</h1>
    <form method="GET" class="filter-bar mt-6">
        <label class="field">开始日期（北京时间）<input type="date" name="from" value="{{ $from }}" required></label>
        <label class="field">结束日期（含当日）<input type="date" name="to" value="{{ $to }}" required></label>
        <button class="btn-secondary" type="submit">查看统计</button>
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
        $todos = [
            ['待退款', $summary->pending_refunds, '列出最早的 5 条待处理或失败退款，含所选日期之外的记录。', $pendingRefunds, '暂无待退款事项。'],
            ['停诊待处理', $summary->suspended_count, '所选就诊日期内，停诊排班仍待支付或已确认的预约，按时间列出最早 5 条。', $suspended, '暂无停诊待处理事项。'],
            ['就诊结果待核实', $summary->unverified_count, '所选就诊日期内，时段已结束且仍为已确认的预约，按时间列出最早 5 条。', $unverified, '暂无待核实事项。'],
        ];
    @endphp
    <section class="mt-8 grid gap-4 lg:grid-cols-3" aria-label="待处理事项">
        @foreach($todos as $index => [$title, $count, $scope, $items, $empty])
            <article class="panel">
                <h2 class="section-title">{{ $title }} <span class="todo-count">{{ $count }}</span></h2>
                <p class="hint mt-1">{{ $scope }}</p>
                @if($items->isEmpty())
                    <p class="hint mt-4">{{ $empty }}</p>
                @else
                    <ul class="todo-list mt-3">
                        @foreach($items as $item)
                            <li>
                                <a class="text-link" href="{{ route('admin.appointments.show', $item->appointment_id) }}">{{ $item->appointment_no }}</a>
                                <div class="cell-status mt-1">
                                    @if($index === 0)
                                        <x-resource-status :value="$item->refund_status" /> <span class="hint">模拟 <span class="num">¥{{ $item->refund_amount }}</span></span>
                                    @else
                                        <span class="hint">{{ $item->department_name }}</span>
                                        @if($index === 1)<x-resource-status :value="$item->appointment_status" />@endif
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @endforeach
    </section>

    <section class="mt-8" aria-labelledby="metrics-title">
        <h2 id="metrics-title" class="section-title">运营指标</h2>
        <div class="metric-grid mt-3">
            @foreach($cards as [$label, $value, $meaning])
                <article class="metric-card">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="metric-label">{{ $label }}</h3>
                        <details class="metric-help min-w-0 text-right">
                            <summary aria-label="{{ $label }}的统计口径">?</summary>
                            <p class="hint mt-1 text-left">{{ $meaning }}</p>
                        </details>
                    </div>
                    <p class="metric-number">{{ $value }}</p>
                </article>
            @endforeach
        </div>
        <p class="hint mt-3">所选就诊日期共 <span class="num">{{ $summary->capacity }}</span> 个名额，已占 <span class="num">{{ $summary->occupied_count }}</span> 个。
            @if($summary->occupancy_mismatches)<span class="status-text--danger">有 {{ $summary->occupancy_mismatches }} 个时段的占用数与预约记录不一致，请核对。</span>@endif
            分母为零的比率显示“—”。
        </p>
    </section>

    @if($detail)
        <section class="mt-8" aria-labelledby="trend-title"><h2 id="trend-title" class="section-title">预约创建数量变化</h2><p class="hint mt-1">按北京时间统计新建预约，包括后来取消或过期的预约。未列日期为零，条形表示与本期单日最高值的比例。</p>
            @if($creationTrend->isEmpty())
                <p class="empty-state mt-4">所选日期没有新建预约，暂无创建趋势。</p>
            @else
                @php($peak = $creationTrend->max('appointment_count'))
                <div class="table-wrap mt-3" tabindex="0" role="region" aria-label="按创建日期统计的预约数量"><table><thead><tr><th>创建日期</th><th class="col-num">新建预约</th><th>相对单日峰值</th></tr></thead><tbody>
                    @foreach($creationTrend as $day)<tr><td><span class="num">{{ $day->creation_date }}</span></td><td class="col-num"><span class="num">{{ $day->appointment_count }}</span></td><td><svg class="bar" width="180" height="12" viewBox="0 0 180 12" aria-hidden="true"><rect class="bar-track" width="180" height="12" rx="2"/><rect class="bar-fill" width="{{ $day->appointment_count * 180 / $peak }}" height="12" rx="2"/></svg></td></tr>@endforeach
                </tbody></table></div>
            @endif
        </section>
        <section class="mt-8" aria-labelledby="dept-title"><h2 id="dept-title" class="section-title">按出诊科室核对</h2><p class="hint mt-1">按就诊日期及出诊时的科室统计。占用率为已占名额占科室总容量的比例。</p>
            <div class="table-wrap mt-3" tabindex="0" role="region" aria-label="科室统计，可横向滚动"><table><thead><tr><th>科室</th><th class="col-num">预约总量</th><th class="col-num">容量 / 占用</th><th>占用率</th><th class="col-num">待支付</th><th class="col-num">已确认</th><th class="col-num">已完成</th><th class="col-num">已取消</th><th class="col-num">已过期</th></tr></thead><tbody>
            @forelse($departments as $department)
                <tr><td><span class="cell-main">{{ $department->department_name }}</span></td><td class="col-num num">{{ $department->pending_payment + $department->confirmed + $department->completed + $department->cancelled + $department->expired }}</td><td class="col-num num">{{ $department->capacity }} / {{ $department->occupied_count }}</td><td class="whitespace-nowrap">
                    @if($summary->occupancy_mismatches)
                        待核对
                    @elseif($department->capacity > 0)
                        @php($percentage = $department->occupied_count * 100 / $department->capacity)
                        <span class="num">{{ number_format($percentage, 1) }}%</span> <svg class="bar" width="120" height="8" viewBox="0 0 120 8" aria-hidden="true"><rect class="bar-track" width="120" height="8" rx="2"/><rect class="bar-fill" width="{{ min(120, max(0, $percentage * 1.2)) }}" height="8" rx="2"/></svg>
                    @else — @endif
                </td><td class="col-num num">{{ $department->pending_payment }}</td><td class="col-num num">{{ $department->confirmed }}</td><td class="col-num num">{{ $department->completed }}</td><td class="col-num num">{{ $department->cancelled }}</td><td class="col-num num">{{ $department->expired }}</td></tr>
            @empty<tr><td colspan="9">暂无科室数据。</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif
@endsection
