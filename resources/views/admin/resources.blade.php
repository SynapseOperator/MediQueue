@extends('layouts.app')
@section('title', '医院资源管理')
@section('content')
    @php
        $departmentNames = $departments->pluck('name', 'id');
        $doctorNames = $doctors->pluck('full_name', 'id');
        $tomorrow = \Carbon\CarbonImmutable::now(config('mediqueue.hospital_timezone'))->addDay()->startOfDay();
    @endphp
    <p class="eyebrow">管理员</p><h1 class="page-title mt-2">医院资源管理</h1>
    <p class="mt-3 max-w-3xl leading-7 text-slate-600">先建立科室和医生，再创建草稿排班、配置时段并发布。停用、关闭或停诊会保留历史关联；停诊不会自动取消既有预约。</p>
    <nav class="mt-6 flex flex-wrap gap-3" aria-label="资源分类">
        <a class="btn-secondary" href="#departments">科室 {{ $departments->count() }}</a><a class="btn-secondary" href="#doctors">医生 {{ $doctors->count() }}</a><a class="btn-secondary" href="#schedules">排班 {{ $schedules->count() }}</a><a class="btn-secondary" href="#slots">时段 {{ $slots->count() }}</a>
    </nav>
    <section id="departments" class="mt-10 scroll-mt-5">
        <h2 class="mb-5 text-2xl font-semibold">科室</h2>
        <details class="panel" @if(old('_form') === 'department-new') open @endif><summary>＋ 创建科室</summary>@include('admin.forms.department', ['record' => null])</details>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @foreach($departments as $record)<details class="panel" @if(old('_form') === 'department-'.$record->id) open @endif><summary>{{ $record->name }} <x-resource-status :value="$record->status" /></summary>@include('admin.forms.department')</details>@endforeach
        </div>
    </section>
    <section id="doctors" class="mt-10 scroll-mt-5">
        <h2 class="mb-5 text-2xl font-semibold">医生</h2>
        <details class="panel" @if(old('_form') === 'doctor-new') open @endif><summary>＋ 创建医生及登录账号</summary>@include('admin.forms.doctor', ['record' => null])</details>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @foreach($doctors as $record)<details class="panel" @if(old('_form') === 'doctor-'.$record->id) open @endif><summary>{{ $record->full_name }} · {{ $departmentNames[$record->department_id] }} <x-resource-status :value="$record->status" /></summary>@include('admin.forms.doctor')</details>@endforeach
        </div>
    </section>
    <section id="schedules" class="mt-10 scroll-mt-5">
        <h2 class="mb-5 text-2xl font-semibold">出诊排班</h2>
        <details class="panel" @if(old('_form') === 'schedule-new') open @endif><summary>＋ 创建排班</summary>@include('admin.forms.schedule', ['record' => null])</details>
        <div class="mt-4 space-y-4">
            @foreach($schedules as $record)<details class="panel" @if(old('_form') === 'schedule-'.$record->id) open @endif><summary>#{{ $record->id }} · {{ $record->work_date }} · {{ $doctorNames[$record->doctor_id] }} <x-resource-status :value="$record->status" /></summary>@include('admin.forms.schedule')</details>@endforeach
        </div>
    </section>
    <section id="slots" class="mt-10 scroll-mt-5">
        <h2 class="mb-5 text-2xl font-semibold">号源时段</h2>
        <details class="panel" @if(old('_form') === 'slot-new') open @endif><summary>＋ 配置时段</summary>@include('admin.forms.slot', ['record' => null])</details>
        <div class="mt-4 space-y-4">
            @foreach($slots as $record)<details class="panel" @if(old('_form') === 'slot-'.$record->id) open @endif><summary>#{{ $record->id }} · 排班 #{{ $record->schedule_id }} · {{ \Carbon\CarbonImmutable::parse($record->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('m-d H:i') }} · 占用 {{ $record->occupied_count }}/{{ $record->capacity }} <x-resource-status :value="$record->status" /></summary>@include('admin.forms.slot')</details>@endforeach
        </div>
    </section>
@endsection
