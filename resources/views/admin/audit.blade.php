@extends('layouts.app')
@section('title', '操作审计')
@section('content')
    <p class="eyebrow">管理工作台 · 只读追溯</p><h1 class="page-title">操作审计</h1>
    <p class="page-intro">按上海发生日期筛选。仅展示动作、对象和操作者编号；不显示密码、个人资料或审计 JSON。USER 为用户操作，SYSTEM 为系统操作。</p>
    <form method="GET" class="panel mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <label class="field">开始日期<input type="date" name="from" value="{{ $filters['from'] }}" required></label>
        <label class="field">结束日期<input type="date" name="to" value="{{ $filters['to'] }}" required></label>
        <label class="field">动作<select name="action"><option value="">全部</option>@foreach($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ $action }}</option>@endforeach</select></label>
        <label class="field">操作者类型<select name="actor_type"><option value="">全部</option>@foreach(['USER', 'SYSTEM'] as $actor)<option value="{{ $actor }}" @selected(($filters['actor_type'] ?? null) === $actor)>{{ $actor }}</option>@endforeach</select></label>
        <label class="field">对象类型<select name="target_type"><option value="">全部</option>@foreach($targets as $target)<option value="{{ $target }}" @selected(($filters['target_type'] ?? null) === $target)>{{ $target }}</option>@endforeach</select></label>
        <label class="field">对象编号<input type="number" name="target_id" min="1" value="{{ $filters['target_id'] ?? '' }}"></label>
        <div class="flex items-end"><button class="btn" type="submit">筛选审计</button></div>
    </form>
    <p class="mt-5 text-sm text-slate-500">共 {{ $logs->total() }} 条记录，每页 20 条。</p>
    <div class="table-wrap mt-3" tabindex="0" role="region" aria-label="审计记录，可横向滚动"><table><thead><tr><th>编号</th><th>北京时间</th><th>动作</th><th>操作者</th><th>对象</th></tr></thead><tbody>
        @forelse($logs as $log)<tr><td>{{ $log->id }}</td><td>{{ \Carbon\CarbonImmutable::parse($log->created_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i:s') }}</td><td>{{ $log->action }}</td><td>{{ $log->actor_type }} {{ $log->operator_id ?? '—' }}</td><td>{{ $log->target_type }} #{{ $log->target_id }}</td></tr>
        @empty<tr><td colspan="5">此条件下暂无审计记录。</td></tr>@endforelse
    </tbody></table></div><div class="mt-5">{{ $logs->links() }}</div>
@endsection
