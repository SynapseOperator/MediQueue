@extends('layouts.app')
@section('title', '医院资源管理')
@section('content')
    @php
        $departmentNames = $departments->pluck('name', 'id');
        $doctorNames = $doctors->pluck('full_name', 'id');
        $tomorrow = \Carbon\CarbonImmutable::now(config('mediqueue.hospital_timezone'))->addDay()->startOfDay();
    @endphp
    <h1 class="page-title">医院资源管理</h1>
    <p class="page-intro">创建科室和医生后，可新增排班、配置时段并开放预约。停诊后请到<a class="text-link" href="{{ route('admin.appointments.index') }}">预约处理</a>逐项核实已有预约。</p>
    <nav class="mt-4 flex flex-wrap gap-2" aria-label="资源分类">
        <a class="btn-secondary" href="#departments">科室 {{ $departments->count() }}</a>
        <a class="btn-secondary" href="#doctors">医生 {{ $doctors->count() }}</a>
        <a class="btn-secondary" href="#schedules">排班 {{ $schedules->count() }}</a>
        <a class="btn-secondary" href="#slots">号源时段 {{ $slots->count() }}</a>
    </nav>
    <section id="departments" class="mt-8">
        <h2 class="section-title">科室</h2>
        <details class="panel mt-3" @if(old('_form') === 'department-new') open @endif>
            <summary>创建科室</summary>
            @include('admin.forms.department', ['record' => null])
        </details>
        <div class="table-wrap mt-3" role="region" tabindex="0" aria-label="科室列表">
            <table>
                <thead><tr><th>科室名称</th><th>编码</th><th>状态</th><th class="text-right">操作</th></tr></thead>
                <tbody>
                    @forelse($departments as $record)
                        <tr>
                            <td>{{ $record->name }}</td>
                            <td><span class="num">{{ $record->code }}</span></td>
                            <td><x-resource-status :value="$record->status" /></td>
                            <td class="text-right"><a class="text-link" href="#edit-department-{{ $record->id }}" aria-label="编辑科室 {{ $record->id }}">编辑</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4">暂无科室。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @foreach($departments as $record)
            <section id="edit-department-{{ $record->id }}" tabindex="-1"
                     @class(['panel mt-3 target:block', 'hidden' => old('_form') !== 'department-'.$record->id])>
                <div class="flex items-center justify-between gap-4">
                    <h3 class="sub-title">编辑科室 #{{ $record->id }}</h3>
                    <a class="text-link" href="#departments">返回列表</a>
                </div>
                @include('admin.forms.department')
            </section>
        @endforeach
    </section>
    <section id="doctors" class="mt-8">
        <h2 class="section-title">医生</h2>
        <details class="panel mt-3" @if(old('_form') === 'doctor-new') open @endif>
            <summary>创建医生及登录账号</summary>
            @include('admin.forms.doctor', ['record' => null])
        </details>
        <div class="table-wrap mt-3" role="region" tabindex="0" aria-label="医生列表">
            <table>
                <thead><tr><th>姓名</th><th>职称</th><th>所属科室</th><th>状态</th><th class="text-right">操作</th></tr></thead>
                <tbody>
                    @forelse($doctors as $record)
                        <tr>
                            <td>{{ $record->full_name }}</td>
                            <td>{{ $record->title }}</td>
                            <td>{{ $departmentNames[$record->department_id] }}</td>
                            <td><x-resource-status :value="$record->status" /></td>
                            <td class="text-right"><a class="text-link" href="#edit-doctor-{{ $record->id }}" aria-label="编辑医生 {{ $record->id }}">编辑</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">暂无医生。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @foreach($doctors as $record)
            <section id="edit-doctor-{{ $record->id }}" tabindex="-1"
                     @class(['panel mt-3 target:block', 'hidden' => old('_form') !== 'doctor-'.$record->id])>
                <div class="flex items-center justify-between gap-4">
                    <h3 class="sub-title">编辑医生 #{{ $record->id }}</h3>
                    <a class="text-link" href="#doctors">返回列表</a>
                </div>
                @include('admin.forms.doctor')
            </section>
        @endforeach
    </section>
    <section id="schedules" class="mt-8">
        <h2 class="section-title">排班</h2>
        <details class="panel mt-3" @if(old('_form') === 'schedule-new') open @endif>
            <summary>创建排班</summary>
            @include('admin.forms.schedule', ['record' => null])
        </details>
        <div class="table-wrap mt-3" role="region" tabindex="0" aria-label="排班列表">
            <table>
                <thead><tr><th>出诊日期</th><th>医生</th><th>出诊时间</th><th>状态</th><th class="text-right">操作</th></tr></thead>
                <tbody>
                    @forelse($schedules as $record)
                        <tr>
                            <td><span class="num">{{ $record->work_date }}</span></td>
                            <td>{{ $doctorNames[$record->doctor_id] }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($record->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($record->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</td>
                            <td><x-resource-status :value="$record->status" /></td>
                            <td class="text-right"><a class="text-link" href="#edit-schedule-{{ $record->id }}" aria-label="编辑排班 {{ $record->id }}">编辑</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">暂无排班。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @foreach($schedules as $record)
            <section id="edit-schedule-{{ $record->id }}" tabindex="-1"
                     @class(['panel mt-3 target:block', 'hidden' => old('_form') !== 'schedule-'.$record->id])>
                <div class="flex items-center justify-between gap-4">
                    <h3 class="sub-title">编辑排班 #{{ $record->id }}</h3>
                    <a class="text-link" href="#schedules">返回列表</a>
                </div>
                @include('admin.forms.schedule')
            </section>
        @endforeach
    </section>
    <section id="slots" class="mt-8">
        <h2 class="section-title">号源时段</h2>
        <details class="panel mt-3" @if(old('_form') === 'slot-new') open @endif>
            <summary>配置时段</summary>
            @include('admin.forms.slot', ['record' => null])
        </details>
        <div class="table-wrap mt-3" role="region" tabindex="0" aria-label="号源时段列表">
            <table>
                <thead><tr><th>排班编号</th><th>就诊时间</th><th class="col-num">占用 / 容量</th><th class="col-num">挂号费</th><th>状态</th><th class="text-right">操作</th></tr></thead>
                <tbody>
                    @forelse($slots as $record)
                        <tr>
                            <td><span class="num">#{{ $record->schedule_id }}</span></td>
                            <td>{{ \Carbon\CarbonImmutable::parse($record->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('m-d H:i') }}–{{ \Carbon\CarbonImmutable::parse($record->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</td>
                            <td class="col-num"><span class="num">{{ $record->occupied_count }} / {{ $record->capacity }}</span></td>
                            <td class="col-num"><span class="num">¥{{ $record->fee_amount }}</span></td>
                            <td><x-resource-status :value="$record->status" /></td>
                            <td class="text-right"><a class="text-link" href="#edit-slot-{{ $record->id }}" aria-label="编辑号源时段 {{ $record->id }}">编辑</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">暂无号源时段。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @foreach($slots as $record)
            <section id="edit-slot-{{ $record->id }}" tabindex="-1"
                     @class(['panel mt-3 target:block', 'hidden' => old('_form') !== 'slot-'.$record->id])>
                <div class="flex items-center justify-between gap-4">
                    <h3 class="sub-title">编辑号源时段 #{{ $record->id }}</h3>
                    <a class="text-link" href="#slots">返回列表</a>
                </div>
                @include('admin.forms.slot')
            </section>
        @endforeach
    </section>
@endsection
