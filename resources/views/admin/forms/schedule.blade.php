@php
    $formKey = 'schedule-'.($record?->id ?? 'new');
    $values = old('_form') === $formKey ? $oldInput : (array) $record;
    if (old('_form') !== $formKey) {
        foreach (['starts_at', 'ends_at'] as $field) {
            $values[$field] = isset($values[$field]) ? \Carbon\CarbonImmutable::parse($values[$field], 'UTC')->setTimezone(config('mediqueue.hospital_timezone'))->format('Y-m-d\TH:i') : $tomorrow->setTime($field === 'starts_at' ? 9 : 10, 0)->format('Y-m-d\TH:i');
        }
    }
@endphp
<form method="POST" action="{{ $record ? route('admin.schedules.update', $record->id) : route('admin.schedules.store') }}" class="mt-4 space-y-3">
    @csrf @if($record) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @if($record)<label class="field">医生<input value="{{ $doctorNames[$record->doctor_id] }}" readonly><input type="hidden" name="doctor_id" value="{{ $record->doctor_id }}">@if(old('_form') === $formKey) @error('doctor_id')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        @else<label class="field"><span>医生 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><select name="doctor_id" required><option value="">请选择</option>@foreach($doctors as $doctor)<option value="{{ $doctor->id }}" @selected((string)($values['doctor_id'] ?? '') === (string)$doctor->id)>{{ $doctor->full_name }}（{{ $departmentNames[$doctor->department_id] }}）</option>@endforeach</select>@if(old('_form') === $formKey) @error('doctor_id')<span class="field-error">{{ $message }}</span>@enderror @endif</label>@endif
        <label class="field"><span>业务日期 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input type="date" name="work_date" value="{{ $values['work_date'] ?? $tomorrow->toDateString() }}" required>@if(old('_form') === $formKey) @error('work_date')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>班次 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><select name="session_code" required>@foreach(['MORNING' => '上午', 'AFTERNOON' => '下午', 'EVENING' => '晚间'] as $code => $label)<option value="{{ $code }}" @selected(($values['session_code'] ?? 'MORNING') === $code)>{{ $label }}</option>@endforeach</select>@if(old('_form') === $formKey) @error('session_code')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>开始时间（北京时间） <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input type="datetime-local" name="starts_at" value="{{ $values['starts_at'] ?? '' }}" required>@if(old('_form') === $formKey) @error('starts_at')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>结束时间（北京时间） <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input type="datetime-local" name="ends_at" value="{{ $values['ends_at'] ?? '' }}" required>@if(old('_form') === $formKey) @error('ends_at')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>排班状态 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><select name="status" required>@foreach(['DRAFT' => '草稿', 'OPEN' => '开放', 'SUSPENDED' => '停诊', 'CLOSED' => '关闭'] as $code => $label)<option value="{{ $code }}" @selected(($values['status'] ?? 'DRAFT') === $code)>{{ $label }}</option>@endforeach</select>@if(old('_form') === $formKey) @error('status')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    </div>
    <label class="field">停诊原因（停诊时必填）<input name="suspend_reason" value="{{ $values['suspend_reason'] ?? '' }}" maxlength="255">@if(old('_form') === $formKey) @error('suspend_reason')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    <p class="hint">排班不能跨日或与同一医生的其他排班重叠，且须包含所有所属时段。已有预约的时间和班次不能修改。</p>
    <button class="btn" type="submit">{{ $record ? '保存排班' : '创建排班' }}</button>
</form>
