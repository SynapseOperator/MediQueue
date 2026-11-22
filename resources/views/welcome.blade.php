@extends('layouts.app')
@section('title', '预约挂号')
@section('content')
    <section class="home-hero" aria-labelledby="home-title">
        <div class="min-w-0">
            <h1 id="home-title" class="home-title">医院预约挂号</h1>
            <p class="page-intro">选择科室或搜索医生，查看近 {{ config('mediqueue.availability_days') }} 天的出诊时段。</p>
            <form id="directory-search" class="home-search" method="get" role="search" action="{{ route('doctors.index') }}">
                <label for="directory-keyword" class="field min-w-0 flex-1">查找科室或医生
                    <input id="directory-keyword" type="search" placeholder="输入科室或医生姓名" autocomplete="off">
                </label>
                <button class="btn shrink-0" type="submit">查询</button>
            </form>
            <p id="directory-count" class="hint mt-2" role="status"></p>
            <ul id="directory-results" class="directory-results" hidden>
                @foreach($departments as $department)
                    <li><a class="text-link" href="{{ route('doctors.index', ['department' => $department->id]) }}"><span>{{ $department->name }}</span><span class="hint">科室</span></a></li>
                @endforeach
                @foreach($directoryDoctors as $doctor)
                    <li><a class="text-link" href="{{ route('doctors.show', $doctor->id) }}"><span>{{ $doctor->full_name }}</span><span class="hint">{{ $doctor->title }}，{{ $doctor->department_name }}</span></a></li>
                @endforeach
            </ul>
        </div>
        <ol class="home-steps" aria-label="挂号流程">
            <li><div><strong>选择科室或医生</strong><span class="hint">按科室浏览，或直接搜索医生姓名。</span></div></li>
            <li><div><strong>选择就诊时段</strong><span class="hint">时段上会显示剩余名额和挂号费。</span></div></li>
            <li><div><strong>核对并付款</strong><span class="hint">请在预约详情显示的截止时间前完成模拟付款。</span></div></li>
        </ol>
    </section>

    <section class="home-section" aria-labelledby="departments-title">
        <div class="section-head">
            <h2 id="departments-title" class="section-title">按科室挂号</h2>
            <a class="text-link" href="{{ route('doctors.index') }}">查看全部医生</a>
        </div>
        <ul class="dept-grid mt-4">
            @forelse($departments as $department)
                <li><a class="dept-link" href="{{ route('doctors.index', ['department' => $department->id]) }}">{{ $department->name }}</a></li>
            @empty
                <li class="empty-state">暂无开放科室。</li>
            @endforelse
        </ul>
    </section>

    <section class="home-section" aria-labelledby="instructions-title">
        <h2 id="instructions-title" class="section-title">就诊须知</h2>
        <ul class="notice-list mt-4">
            <li><b>付款时限</b><span>预约后最多有 15 分钟付款。若就诊更早开始，付款将在就诊开始时截止。</span></li>
            <li><b>取消预约</b><span>就诊开始前，可在「我的预约」中取消预约。</span></li>
            <li><b>退款</b><span>已付款的预约取消后将办理退款，可在预约详情查看进度。</span></li>
            <li><b>时间</b><span>排班和就诊时间均为北京时间。</span></li>
        </ul>
    </section>
@endsection
