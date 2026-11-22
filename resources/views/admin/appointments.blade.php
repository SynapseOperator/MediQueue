@extends('layouts.app')
@section('title', '预约处理')
@section('content')
    <h1 class="page-title">预约处理</h1>
    <form method="GET" class="filter-bar mt-6">
        <label class="field">预约状态
            <select name="status"><option value="">全部状态</option>
                @foreach(['PENDING_PAYMENT'=>'待支付','CONFIRMED'=>'已确认','CANCELLED'=>'已取消','EXPIRED'=>'已过期','COMPLETED'=>'已完成'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="field">科室
            <select name="department"><option value="">全部科室</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string)request('department') === (string)$department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field">关注事项
            <select name="attention"><option value="">全部事项</option>
                @foreach(['refund'=>'待退款','suspended'=>'停诊','unverified'=>'就诊待核实'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('attention') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn-secondary" type="submit">筛选</button>
        <a class="text-link" href="{{ route('admin.appointments.index') }}">清除筛选</a>
    </form>
    <p class="hint mt-6">共 {{ $appointments->total() }} 条预约，就诊时间为北京时间。</p>
    <div class="table-wrap mt-2" tabindex="0" role="region" aria-label="预约处理列表">
        <table class="appointment-table">
            <thead><tr><th>就诊时间</th><th>就诊人</th><th>科室及医生</th><th>状态</th><th class="col-num">挂号费</th><th class="text-right">操作</th></tr></thead>
            <tbody>
                @forelse($appointments as $appointment)
                    @php($start = \Carbon\CarbonImmutable::parse($appointment->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone')))
                    <tr class="appointment-row" data-status="{{ $appointment->status }}" data-refund="{{ $appointment->refund_status }}">
                        <td data-label="就诊时间">
                            <span class="cell-time">{{ $start->format('H:i') }}</span>
                            <span class="cell-sub num">{{ $start->locale('zh_CN')->isoFormat('YYYY-MM-DD ddd') }}</span>
                        </td>
                        <td data-label="就诊人">
                            <span class="cell-main">{{ $appointment->patient_name }}</span>
                            <span class="cell-sub num">{{ $appointment->appointment_no }}</span>
                        </td>
                        <td data-label="科室及医生">
                            <span class="cell-main">{{ $appointment->department_name }}</span>
                            <span class="cell-sub">{{ $appointment->doctor_name }}</span>
                        </td>
                        <td data-label="状态">
                            <div class="cell-status">
                                <x-resource-status :value="$appointment->status" />
                                <x-resource-status :value="$appointment->order_status" variant="text" />
                                @if($appointment->refund_status)<x-resource-status :value="$appointment->refund_status" variant="text" />@endif
                            </div>
                        </td>
                        <td class="col-num" data-label="挂号费"><span class="num">¥{{ $appointment->fee_amount }}</span></td>
                        <td class="text-right" data-label="操作"><a class="btn-secondary btn-sm" href="{{ route('admin.appointments.show', $appointment->id) }}">查看并处理</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6">暂无符合条件的预约。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $appointments->links() }}</div>
@endsection
