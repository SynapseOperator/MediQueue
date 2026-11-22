@extends('layouts.app')
@section('title', $doctor->full_name)
@section('content')
    <a class="text-link back-link" href="{{ route('doctors.index') }}">返回医生列表</a>
    <section class="panel mt-2">
        <div class="doctor-head">
            <span class="doctor-avatar doctor-avatar--large" aria-hidden="true">{{ mb_substr($doctor->full_name, 0, 1) }}</span>
            <div class="min-w-0">
                <h1 class="page-title">{{ $doctor->full_name }}</h1>
                <p class="doctor-meta"><span>{{ $doctor->title }}</span><span>{{ $doctor->department_name }}</span></p>
            </div>
        </div>
        <p class="mt-4"><span class="hint">擅长</span> {{ $doctor->specialty ?: '暂未填写' }}</p>
        @if($doctor->introduction)
            <p class="page-intro whitespace-pre-line">{{ $doctor->introduction }}</p>
        @endif
    </section>
    <section class="panel mt-6" aria-labelledby="schedule-heading">
        <div class="section-head">
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
                        <span class="num">{{ $day->format('m/d') }}</span>
                        <span>{{ $availability[$date] ? '可约' : '已满' }}</span>
                    </a>
                @else
                    <span aria-disabled="true" @if($date === $selectedDate) aria-current="date" @endif>
                        <span>{{ $day->locale('zh_CN')->isoFormat('ddd') }}</span>
                        <span class="num">{{ $day->format('m/d') }}</span>
                        <span>无号</span>
                    </span>
                @endif
            @endfor
        </nav>

        @forelse($slots->getCollection()->groupBy(fn ($slot) => $slot->local_start->hour < 12 ? '上午' : '下午') as $period => $entries)
            <h3 class="period-title">{{ $period }}</h3>
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
                            <span class="slot-meta">登录后预约</span>
                        </a>
                    @else
                        <button type="button" disabled @class(['booking-slot', 'is-full' => $remaining === 0])>
                            @include('hospital.slot-content')
                            @if($remaining > 0)<span class="slot-meta">仅患者可预约</span>@endif
                        </button>
                    @endif
                @endforeach
            </div>
        @empty
            <p class="empty-state mt-4">当前日期暂无可预约时段。<a class="text-link" href="{{ route('doctors.index') }}">选择其他医生</a></p>
        @endforelse
        <div class="mt-4">{{ $slots->links() }}</div>
    </section>
@endsection
