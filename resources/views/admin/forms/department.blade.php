@php
    $formKey = 'department-'.($record?->id ?? 'new');
    $values = old('_form') === $formKey ? $oldInput : (array) $record;
@endphp
<form method="POST" action="{{ $record ? route('admin.departments.update', $record->id) : route('admin.departments.store') }}" class="mt-6 space-y-5">
    @csrf @if($record) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">
    <div class="grid gap-5 sm:grid-cols-2">
        <label class="field">科室编码<input name="code" value="{{ $values['code'] ?? '' }}" required maxlength="32"></label>
        <label class="field">科室名称<input name="name" value="{{ $values['name'] ?? '' }}" required maxlength="64"></label>
    </div>
    <label class="field">科室介绍<textarea name="introduction" rows="3" maxlength="16000">{{ $values['introduction'] ?? '' }}</textarea></label>
    <label class="field">科室状态<select name="status"><option value="ACTIVE" @selected(($values['status'] ?? 'ACTIVE') === 'ACTIVE')>启用</option><option value="DISABLED" @selected(($values['status'] ?? '') === 'DISABLED')>停用</option></select></label>
    <p class="hint">停用后停止公开展示。请核对受影响的未来排班；既有预约历史不会被删除。</p>
    <button class="btn" type="submit">{{ $record ? '保存科室' : '创建科室' }}</button>
</form>
