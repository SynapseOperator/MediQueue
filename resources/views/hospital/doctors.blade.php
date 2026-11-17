@extends('layouts.app')
@section('title', '医生列表')
@section('content')
    <p class="eyebrow">医院资源</p><h1 class="page-title mt-2">医生与出诊安排</h1>
    <form method="GET" action="{{ route('doctors.index') }}" class="surface-muted mt-6 flex flex-wrap items-end gap-3">
        <label class="field min-w-52">选择科室<select name="department"><option value="">全部科室</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)$departmentId === (string)$department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <button class="btn" type="submit">筛选</button>
    </form>
    <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
        @forelse($doctors as $doctor)
            <article class="panel flex flex-col"><div aria-hidden="true" class="mb-5 grid h-12 w-12 place-items-center rounded-2xl bg-[#e5f2ef] text-lg font-bold text-teal-700">医</div><p class="eyebrow">{{ $doctor->department_name }}</p><h2 class="mt-2 text-xl font-semibold">{{ $doctor->full_name }} <span class="text-sm font-normal text-slate-500">{{ $doctor->title }}</span></h2><p class="mt-4 leading-7 text-slate-600">{{ $doctor->specialty ?: '暂未填写专长介绍。' }}</p><a class="text-link mt-auto inline-block pt-6" href="{{ route('doctors.show', $doctor->id) }}">简介与出诊时段 →</a></article>
        @empty
            <p class="panel col-span-full text-slate-600">当前筛选下暂无开放医生。</p>
        @endforelse
    </div>
    <div class="mt-7">{{ $doctors->links() }}</div>
@endsection
