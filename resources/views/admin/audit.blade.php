@extends('layouts.app')
@section('title', '操作审计')
@section('content')
    @php
        $actionNames = [
            'ADMIN_CREATED'=>'创建管理员','DEMO_PASSWORD_RESET'=>'重置账号密码','PATIENT_REGISTERED'=>'患者注册','PATIENT_PROFILE_UPDATED'=>'更新患者资料',
            'DEPARTMENT_SAVED'=>'保存科室','DOCTOR_SAVED'=>'保存医生','SCHEDULE_SAVED'=>'保存排班','SLOT_SAVED'=>'保存时段','APPOINTMENT_CREATED'=>'创建预约',
            'PAYMENT_CONFIRMED'=>'确认付款','PAYMENT_DECLINED'=>'付款被拒','PAYMENT_UNKNOWN'=>'付款结果待核实','APPOINTMENT_CANCELLED'=>'取消预约',
            'APPOINTMENT_EXPIRED'=>'回收到期预约','APPOINTMENT_COMPLETED'=>'登记就诊完成','REFUND_SUCCEEDED'=>'退款成功','REFUND_FAILED'=>'退款失败','REFUND_PENDING'=>'退款待处理',
        ];
        $targetNames = ['users'=>'账号','patients'=>'患者','departments'=>'科室','doctors'=>'医生','schedules'=>'排班','slots'=>'号源','appointments'=>'预约','payment_orders'=>'订单','refunds'=>'退款'];
    @endphp
    <h1 class="page-title">操作审计</h1>
    <form method="GET" class="panel mt-6 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="field">开始日期<input type="date" name="from" value="{{ $filters['from'] }}" required></label>
        <label class="field">结束日期<input type="date" name="to" value="{{ $filters['to'] }}" required></label>
        <label class="field">操作
            <select name="action"><option value="">全部操作</option>
                @foreach($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ $actionNames[$action] }}</option>@endforeach
            </select>
        </label>
        <label class="field">操作者类型
            <select name="actor_type"><option value="">全部</option>
                @foreach(['USER'=>'用户','SYSTEM'=>'系统'] as $actor => $name)<option value="{{ $actor }}" @selected(($filters['actor_type'] ?? null) === $actor)>{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="field">对象类型
            <select name="target_type"><option value="">全部</option>
                @foreach($targets as $target)<option value="{{ $target }}" @selected(($filters['target_type'] ?? null) === $target)>{{ $targetNames[$target] }}</option>@endforeach
            </select>
        </label>
        <label class="field">对象编号<input type="number" name="target_id" min="1" value="{{ $filters['target_id'] ?? '' }}"></label>
        <div><button class="btn-secondary" type="submit">筛选</button></div>
    </form>
    <p class="hint mt-6">共 <span class="num">{{ $logs->total() }}</span> 条记录，时间为北京时间。</p>
    <div class="table-wrap mt-2" tabindex="0" role="region" aria-label="审计记录">
        <table>
            <thead><tr><th class="col-num">编号</th><th>时间</th><th>操作</th><th>操作者</th><th>对象</th><th>状态变更</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                @php($change = $changes->get($log->id))
                <tr>
                    <td class="col-num"><span class="num">{{ $log->id }}</span></td>
                    <td class="whitespace-nowrap"><span class="num">{{ \Carbon\CarbonImmutable::parse($log->created_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d H:i:s') }}</span></td>
                    <td>{{ $actionNames[$log->action] ?? '其他操作' }}</td>
                    <td>{{ $log->actor_type === 'USER' ? '用户' : '系统' }} <span class="num">{{ $log->operator_id }}</span></td>
                    <td>{{ $targetNames[$log->target_type] ?? '其他对象' }} <span class="num">#{{ $log->target_id }}</span></td>
                    <td>
                        @if($change && ($change->before_status || $change->after_status))
                            <details><summary class="text-link">查看变更</summary>
                                <dl class="mt-2 space-y-2 text-sm">
                                    <div class="flex gap-2"><dt class="hint">变更前</dt><dd>@if($change->before_status)<x-resource-status :value="$change->before_status" />@else — @endif</dd></div>
                                    <div class="flex gap-2"><dt class="hint">变更后</dt><dd>@if($change->after_status)<x-resource-status :value="$change->after_status" />@else — @endif</dd></div>
                                </dl>
                            </details>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">此条件下暂无审计记录。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $logs->links() }}</div>
@endsection
