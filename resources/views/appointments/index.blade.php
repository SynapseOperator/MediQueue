@extends('layouts.app')
@section('title', '我的预约')
@section('content')
    <h1 class="page-title">我的预约</h1>
    <p class="mt-3 text-slate-600">提交结果不确定时，可在这里查看本人预约。</p>
    <div class="mt-6 space-y-3">
        @forelse($appointments as $appointment)
            <div class="panel"><a class="text-link" href="{{ route('appointments.show', $appointment->id) }}">{{ $appointment->appointment_no }}</a><p class="mt-2">{{ $appointment->status }}</p></div>
        @empty<p>暂无预约。</p>@endforelse
    </div>
    <div class="mt-6">{{ $appointments->links() }}</div>
@endsection
