@extends('layouts.app')
@section('title', '科室介绍')
@section('content')
    <h1 class="page-title">科室介绍</h1>
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($departments as $department)
            <article class="panel">
                <h2 class="section-title"><a class="text-link" href="{{ route('doctors.index', ['department' => $department->id]) }}">{{ $department->name }}</a></h2>
                @if($department->introduction)
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $department->introduction }}</p>
                @endif
            </article>
        @empty
            <p class="empty-state col-span-full">暂无开放科室。</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $departments->links() }}</div>
@endsection

