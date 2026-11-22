<span class="slot-hour">{{ $slot->local_start->format('H:i') }}–{{ $slot->local_end->format('H:i') }}</span>
<span class="slot-meta">{{ $remaining ? '余 '.$remaining.' 号' : '已满' }}，共 {{ $slot->capacity }} 号</span>
<span class="slot-price">¥{{ $slot->fee_amount }}</span>
@if($slot->department_name !== $doctor->department_name)
    <span class="slot-meta">{{ $slot->department_name }}</span>
@endif
