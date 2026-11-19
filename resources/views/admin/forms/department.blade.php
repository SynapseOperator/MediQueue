@php
    $formKey = 'department-'.($record?->id ?? 'new');
    $values = old('_form') === $formKey ? $oldInput : (array) $record;
@endphp
<form method="POST" action="{{ $record ? route('admin.departments.update', $record->id) : route('admin.departments.store') }}" class="mt-4 space-y-3">
    @csrf @if($record) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">
    <div class="grid gap-3 sm:grid-cols-2">
        <label class="field"><span>科室编码 <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><input name="code" value="{{ $values['code'] ?? '' }}" required maxlength="32">@if(old('_form') === $formKey) @error('code')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>科室名称 <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><input name="name" value="{{ $values['name'] ?? '' }}" required maxlength="64">@if(old('_form') === $formKey) @error('name')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    </div>
    <label class="field">科室介绍<textarea name="introduction" rows="2" maxlength="16000">{{ $values['introduction'] ?? '' }}</textarea>@if(old('_form') === $formKey) @error('introduction')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    <label class="field"><span>科室状态 <span aria-hidden="true" class="text-red-800">*</span><span class="sr-only">（必填）</span></span><select name="status" required><option value="ACTIVE" @selected(($values['status'] ?? 'ACTIVE') === 'ACTIVE')>启用</option><option value="DISABLED" @selected(($values['status'] ?? '') === 'DISABLED')>停用</option></select>@if(old('_form') === $formKey) @error('status')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    <p class="hint">停用后停止公开展示。请核对受影响的未来排班；既有预约历史不会被删除。</p>
    <button class="btn" type="submit">{{ $record ? '保存科室' : '创建科室' }}</button>
</form>
