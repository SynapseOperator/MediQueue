@extends('layouts.app')
@section('title', '科室介绍')
@section('content')
    <p class="eyebrow">医院资源</p><h1 class="page-title mt-2">科室介绍</h1>
    <p class="page-intro">了解各科室，查看当前提供服务的医生。</p>
    <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
        @forelse($departments as $department)
            <article class="panel flex flex-col"><div aria-hidden="true" class="mb-5 grid h-12 w-12 place-items-center rounded-2xl bg-teal-50 text-xl font-semibold text-teal-700">✚</div><h2 class="text-xl font-semibold">{{ $department->name }}</h2><p class="mt-3 whitespace-pre-line leading-7 text-slate-600">{{ $department->introduction ?: '该科室暂未提供详细介绍。' }}</p><a class="text-link mt-auto inline-block pt-6" href="{{ route('doctors.index', ['department' => $department->id]) }}">查看科室医生 →</a></article>
        @empty
            <p class="panel col-span-full text-slate-600">暂无开放科室，请稍后再查看。</p>
        @endforelse
    </div>
    <div class="mt-7">{{ $departments->links() }}</div>
@endsection
