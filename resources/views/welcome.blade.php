@extends('layouts.app')
@section('title', '预约挂号')
@section('content')
    <section class="panel" aria-labelledby="home-title">
        <h1 id="home-title" class="page-title">医院预约挂号</h1>
        <form id="directory-search" class="mt-4 flex max-w-2xl items-end gap-2" method="get" role="search" action="{{ route('doctors.index') }}">
            <label for="directory-keyword" class="field min-w-0 flex-1">查找科室或医生
                <input id="directory-keyword" type="search" placeholder="输入科室或医生姓名" autocomplete="off">
            </label>
            <button class="btn shrink-0" type="submit">查询</button>
        </form>
        <p id="directory-count" class="hint mt-2" role="status"></p>
        <ul id="directory-results" class="mt-2 max-h-64 overflow-y-auto" hidden>
            @foreach($departments as $department)
                <li><a class="text-link py-1" href="{{ route('doctors.index', ['department' => $department->id]) }}">{{ $department->name }}</a></li>
            @endforeach
            @foreach($directoryDoctors as $doctor)
                <li><a class="text-link py-1" href="{{ route('doctors.show', $doctor->id) }}">{{ $doctor->full_name }} · {{ $doctor->department_name }} · {{ $doctor->title }}</a></li>
            @endforeach
        </ul>
    </section>

    <section class="panel mt-6" aria-labelledby="departments-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="departments-title" class="section-title">科室导航</h2>
            <a class="text-link" href="{{ route('doctors.index') }}">全部医生</a>
        </div>
        <ul class="dept-grid mt-4">
            @forelse($departments as $department)
                <li><a class="dept-link block" href="{{ route('doctors.index', ['department' => $department->id]) }}">{{ $department->name }}</a></li>
            @empty
                <li class="hint">暂无开放科室。</li>
            @endforelse
        </ul>
    </section>

    <section class="panel mt-6" aria-labelledby="instructions-title">
        <h2 id="instructions-title" class="section-title">就诊须知</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 text-sm">
            <li>预约后请在 15 分钟内付款，付款截止时间最晚为就诊开始时刻。</li>
            <li>就诊开始前，可在「我的预约」中取消预约。</li>
            <li>已付款的预约取消后将办理退款，可在预约详情查看进度。</li>
            <li>排班和就诊时间均为北京时间。</li>
        </ul>
    </section>
@endsection

