<span class="slot-hour">{{ $slot->local_start->format('H:i') }}–{{ $slot->local_end->format('H:i') }} · {{ $remaining ? '余 '.$remaining : '已满' }}</span>
<span class="slot-price">¥{{ $slot->fee_amount }} <span class="text-xs">／ 共 {{ $slot->capacity }} 号</span></span>
@if($slot->department_name !== $doctor->department_name)
    <span class="text-xs">{{ $slot->department_name }}</span>
@endif

