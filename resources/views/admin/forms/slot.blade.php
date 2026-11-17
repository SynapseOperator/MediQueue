@php
    $formKey = 'slot-'.($record?->id ?? 'new');
    $values = old('_form') === $formKey ? $oldInput : (array) $record;
    if (old('_form') !== $formKey) {
        foreach (['starts_at', 'ends_at'] as $field) {
            $values[$field] = isset($values[$field]) ? \Carbon\CarbonImmutable::parse($values[$field], 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d\TH:i') : $tomorrow->setTime(9, $field === 'starts_at' ? 0 : 30)->format('Y-m-d\TH:i');
        }
    }
@endphp
<form method="POST" action="{{ $record ? route('admin.slots.update', $record->id) : route('admin.slots.store') }}" class="mt-6 space-y-5">
    @csrf @if($record) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">
    @if($record)<label class="field">所属排班<input value="排班 #{{ $record->schedule_id }}" readonly><input type="hidden" name="schedule_id" value="{{ $record->schedule_id }}"></label>
    @else<label class="field">所属排班<select name="schedule_id" required><option value="">请选择</option>@foreach($schedules as $schedule)<option value="{{ $schedule->id }}" @selected((string)($values['schedule_id'] ?? '') === (string)$schedule->id)>#{{ $schedule->id }} · {{ $schedule->work_date }} · {{ $doctorNames[$schedule->doctor_id] }} · {{ \Carbon\CarbonImmutable::parse($schedule->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($schedule->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</option>@endforeach</select></label>@endif
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <label class="field">开始时间（北京时间）<input type="datetime-local" name="starts_at" value="{{ $values['starts_at'] ?? '' }}" required></label>
        <label class="field">结束时间（北京时间）<input type="datetime-local" name="ends_at" value="{{ $values['ends_at'] ?? '' }}" required></label>
        <label class="field">名额容量<input type="number" name="capacity" value="{{ $values['capacity'] ?? 1 }}" required min="1" max="2147483647"></label>
        <label class="field">挂号费（元）<input name="fee_amount" value="{{ $values['fee_amount'] ?? '12.00' }}" required inputmode="decimal" pattern="[0-9]+\.[0-9]{2}"><span class="hint">正数，保留两位小数，例如 12.00。</span></label>
        <label class="field">时段状态<select name="status"><option value="OPEN" @selected(($values['status'] ?? 'OPEN') === 'OPEN')>开放</option><option value="CLOSED" @selected(($values['status'] ?? '') === 'CLOSED')>关闭</option></select></label>
    </div>
    <p class="hint">时段必须位于排班内且互不重叠。容量不能低于已占用数，开始后冻结容量；价格变更仅影响后续预约。草稿排班的时段不会公开展示。</p>
    <button class="btn" type="submit">{{ $record ? '保存时段' : '创建时段' }}</button>
</form>
