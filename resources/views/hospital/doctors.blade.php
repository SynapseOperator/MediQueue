@extends('layouts.app')
@section('title', '医生列表')
@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">FIND YOUR DOCTOR · 医生导览</p>
            <h1 class="page-title mt-2">找到合适的医生</h1>
            <p class="page-intro">按科室筛选开放医生，查看专业方向与近期出诊时段。</p>
        </div>
        <a class="btn-secondary" href="{{ route('departments.index') }}">浏览全部科室</a>
    </div>
    <form method="GET" action="{{ route('doctors.index') }}" class="panel mt-7 flex flex-wrap items-end gap-4">
        <label class="field w-full flex-1 sm:min-w-60">选择科室
            <select name="department"><option value="">全部科室</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)$departmentId === (string)$department->id)>{{ $department->name }}</option>@endforeach</select>
        </label>
        <button class="btn w-full sm:w-auto" type="submit">查看医生</button>
    </form>
    <div class="mt-8 flex items-center justify-between gap-4">
        <h2 class="text-sm font-bold text-slate-600">当前筛选结果</h2>
        <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-500">共 {{ $doctors->total() }} 位医生</span>
    </div>
    <div class="mt-4 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
        @forelse($doctors as $doctor)
            <article class="doctor-card">
                <div class="doctor-portrait">
                    <div class="doctor-avatar" aria-hidden="true">医</div>
                    <span class="absolute bottom-3 right-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-[#167288]">当前开放</span>
                </div>
                <div class="flex flex-1 flex-col p-6">
                    <p class="eyebrow">{{ $doctor->department_name }}</p>
                    <div class="mt-2 flex flex-wrap items-baseline gap-2">
                        <h3 class="text-xl font-bold text-slate-800">{{ $doctor->full_name }}</h3>
                        <span class="text-sm font-medium text-slate-500">{{ $doctor->title }}</span>
                    </div>
                    <p class="mt-4 line-clamp-2 min-h-12 text-sm leading-6 text-slate-600">{{ $doctor->specialty ?: '可查看医生详情与目前开放的出诊安排。' }}</p>
                    <div class="mt-auto border-t border-slate-100 pt-5">
                        <a class="btn w-full" href="{{ route('doctors.show', $doctor->id) }}">查看简介与出诊时段 <span aria-hidden="true">→</span></a>
                    </div>
                </div>
            </article>
        @empty
            <div class="empty-state col-span-full">
                <p class="text-lg font-bold text-slate-700">当前暂无开放医生</p>
                <p class="mt-2 text-sm">可以尝试选择其他科室，或稍后再来查看。</p>
                <a class="btn-secondary mt-5" href="{{ route('doctors.index') }}">显示全部科室</a>
            </div>
        @endforelse
    </div>
    <div class="mt-8">{{ $doctors->links() }}</div>
@endsection
