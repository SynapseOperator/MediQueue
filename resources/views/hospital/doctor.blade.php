@extends('layouts.app')
@section('title', $doctor->full_name)
@section('content')
    <a class="text-link text-sm" href="{{ route('doctors.index') }}">返回医生列表</a>
    <section class="panel mt-3">
        <div class="flex items-center gap-3">
            <span class="doctor-avatar" aria-hidden="true">{{ mb_substr($doctor->full_name, 0, 1) }}</span>
            <div class="min-w-0">
                <h1 class="page-title">{{ $doctor->full_name }}</h1>
                <p class="mt-1 text-sm">{{ $doctor->title }} · {{ $doctor->department_name }}</p>
            </div>
        </div>
        <p class="mt-3 text-sm">擅长：{{ $doctor->specialty ?: '暂未填写' }}</p>
        @if($doctor->introduction)
            <p class="mt-2 whitespace-pre-line text-sm">{{ $doctor->introduction }}</p>
        @endif
    </section>
    <section class="panel mt-6" aria-labelledby="schedule-heading">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="schedule-heading" class="section-title">选择就诊时段</h2>
            <span class="hint">北京时间</span>
        </div>
        <nav class="date-tabs mt-4" aria-label="按就诊日期筛选">
            @for($day = $today; $day <= $lastDay; $day = $day->addDay())
                @php($date = $day->toDateString())
                @if($availability->has($date))
                    <a href="{{ route('doctors.show', ['doctor' => $doctor->id, 'date' => $date]) }}"
                        @if($date === $selectedDate) aria-current="date" @endif
                        @class(['is-sold-out' => ! $availability[$date]])>
                        <span>{{ $day->locale('zh_CN')->isoFormat('ddd') }}</span>
                        <span>{{ $day->format('m/d') }}</span>
                        <span class="text-xs">{{ $availability[$date] ? '可约' : '已满' }}</span>
                    </a>
                @else
                    <span aria-disabled="true" @if($date === $selectedDate) aria-current="date" @endif>
                        <span>{{ $day->locale('zh_CN')->isoFormat('ddd') }}</span>
                        <span>{{ $day->format('m/d') }}</span>
                        <span class="text-xs">无号</span>
                    </span>
                @endif
            @endfor
        </nav>

        @forelse($slots->getCollection()->groupBy(fn ($slot) => $slot->local_start->hour < 12 ? '上午' : '下午') as $period => $entries)
            <h3 class="mb-2 mt-5 font-semibold">{{ $period }}</h3>
            <div class="slot-grid">
                @foreach($entries as $slot)
                    @php($remaining = $slot->capacity - $slot->occupied_count)
                    @if($remaining > 0 && auth()->user()?->role === 'PATIENT')
                        @php($oldKey = old('request_key'))
                        <form action="{{ route('appointments.confirm') }}" method="GET">
                            <input type="hidden" name="slot_id" value="{{ $slot->id }}">
                            <input type="hidden" name="request_key" value="{{ old('slot_id') == $slot->id && is_string($oldKey) && preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $oldKey) ? $oldKey : (string) \Illuminate\Support\Str::uuid() }}">
                            <button type="submit" @class(['booking-slot w-full', 'is-low' => $remaining === 1])>
                                @include('hospital.slot-content')
                            </button>
                        </form>
                    @elseif($remaining > 0 && auth()->guest())
                        <a href="{{ route('login') }}" @class(['booking-slot', 'is-low' => $remaining === 1])>
                            @include('hospital.slot-content')
                            <span class="text-xs">登录后预约</span>
                        </a>
                    @else
                        <button type="button" disabled @class(['booking-slot', 'is-full' => $remaining === 0])>
                            @include('hospital.slot-content')
                            @if($remaining > 0)<span class="text-xs">仅患者可预约</span>@endif
                        </button>
                    @endif
                @endforeach
            </div>
        @empty
            <p class="empty-state mt-4">当前日期暂无可预约时段。<a class="text-link" href="{{ route('doctors.index') }}">选择其他医生</a></p>
        @endforelse
        {{ $slots->links() }}
    </section>
@endsection

