@php
    $formKey = 'slot-'.($record?->id ?? 'new');
    $values = old('_form') === $formKey ? $oldInput : (array) $record;
    if (old('_form') !== $formKey) {
        foreach (['starts_at', 'ends_at'] as $field) {
            $values[$field] = isset($values[$field]) ? \Carbon\CarbonImmutable::parse($values[$field], 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d\TH:i') : $tomorrow->setTime(9, $field === 'starts_at' ? 0 : 30)->format('Y-m-d\TH:i');
        }
    }
@endphp
<form method="POST" action="{{ $record ? route('admin.slots.update', $record->id) : route('admin.slots.store') }}" class="mt-4 space-y-3">
    @csrf @if($record) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">
    @if($record)<label class="field">所属排班<input value="排班 #{{ $record->schedule_id }}" readonly><input type="hidden" name="schedule_id" value="{{ $record->schedule_id }}">@if(old('_form') === $formKey) @error('schedule_id')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    @else<label class="field"><span>所属排班 <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><select name="schedule_id" required><option value="">请选择</option>@foreach($schedules as $schedule)<option value="{{ $schedule->id }}" @selected((string)($values['schedule_id'] ?? '') === (string)$schedule->id)>#{{ $schedule->id }} · {{ $schedule->work_date }} · {{ $doctorNames[$schedule->doctor_id] }} · {{ \Carbon\CarbonImmutable::parse($schedule->starts_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($schedule->ends_at, 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('H:i') }}</option>@endforeach</select>@if(old('_form') === $formKey) @error('schedule_id')<span class="field-error">{{ $message }}</span>@enderror @endif</label>@endif
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label class="field"><span>开始时间（北京时间） <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><input type="datetime-local" name="starts_at" value="{{ $values['starts_at'] ?? '' }}" required>@if(old('_form') === $formKey) @error('starts_at')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>结束时间（北京时间） <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><input type="datetime-local" name="ends_at" value="{{ $values['ends_at'] ?? '' }}" required>@if(old('_form') === $formKey) @error('ends_at')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>名额容量 <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><input type="number" name="capacity" value="{{ $values['capacity'] ?? 1 }}" required min="1" max="2147483647">@if(old('_form') === $formKey) @error('capacity')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>挂号费（元） <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><input name="fee_amount" value="{{ $values['fee_amount'] ?? '12.00' }}" required inputmode="decimal" pattern="[0-9]+\.[0-9]{2}"><span class="hint">正数，保留两位小数，例如 12.00。</span>@if(old('_form') === $formKey) @error('fee_amount')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>时段状态 <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><select name="status" required><option value="OPEN" @selected(($values['status'] ?? 'OPEN') === 'OPEN')>开放</option><option value="CLOSED" @selected(($values['status'] ?? '') === 'CLOSED')>关闭</option></select>@if(old('_form') === $formKey) @error('status')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    </div>
    <p class="hint">时段必须位于排班内且互不重叠。容量不能低于已占用数，开始后冻结容量；价格变更仅影响后续预约。草稿排班的时段不会公开展示。</p>
    <button class="btn" type="submit">{{ $record ? '保存时段' : '创建时段' }}</button>
</form>
