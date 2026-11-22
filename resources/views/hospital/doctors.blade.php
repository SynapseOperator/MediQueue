@extends('layouts.app')
@section('title', '医生列表')
@section('content')
    <h1 class="page-title">医生列表</h1>
    <form method="GET" action="{{ route('doctors.index') }}" class="filter-bar mt-6">
        <label class="field grow sm:max-w-xs" for="doctor-department">科室
            <select name="department" id="doctor-department">
                <option value="">全部科室</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) $departmentId === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn-secondary" type="submit">查询</button>
    </form>
    <p class="hint mt-6">共 <span class="num">{{ $doctors->total() }}</span> 位医生</p>
    <div class="mt-2 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse($doctors as $doctor)
            <article class="panel flex flex-col">
                <div class="doctor-head">
                    <span class="doctor-avatar" aria-hidden="true">{{ mb_substr($doctor->full_name, 0, 1) }}</span>
                    <div class="min-w-0">
                        <h2 class="section-title">{{ $doctor->full_name }}</h2>
                        <p class="doctor-meta"><span>{{ $doctor->title }}</span><span>{{ $doctor->department_name }}</span></p>
                    </div>
                </div>
                <p class="my-3 text-sm"><span class="hint">擅长</span> {{ $doctor->specialty ?: '暂未填写' }}</p>
                <div class="card-foot">
                    @if($doctor->next_date)
                        <span>最近可约 <span class="num">{{ $doctor->next_date }}</span></span>
                    @else
                        <span class="hint">近期暂无可约号源</span>
                    @endif
                    <a class="btn-secondary btn-sm" href="{{ route('doctors.show', $doctor->id) }}" aria-label="查看{{ $doctor->full_name }}的出诊时段">查看排班</a>
                </div>
            </article>
        @empty
            <p class="empty-state col-span-full">暂无开放医生。<a class="text-link" href="{{ route('doctors.index') }}">查看全部科室的医生</a></p>
        @endforelse
    </div>
    <div class="mt-4">{{ $doctors->links() }}</div>
@endsection
