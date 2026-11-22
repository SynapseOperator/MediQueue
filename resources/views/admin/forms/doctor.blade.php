@php
    $formKey = 'doctor-'.($record?->id ?? 'new');
    $values = old('_form') === $formKey ? $oldInput : (array) $record;
@endphp
<form method="POST" action="{{ $record ? route('admin.doctors.update', $record->id) : route('admin.doctors.store') }}" class="mt-4 space-y-3">
    @csrf @if($record) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">
    @unless($record)
        <label class="field"><span>登录用户名 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input name="username" value="{{ $values['username'] ?? '' }}" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_]+" autocomplete="off"><span class="hint">3–64 位字母、数字或下划线，不区分大小写。账号固定为医生身份。</span>@if(old('_form') === $formKey) @error('username')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <div class="grid gap-3 sm:grid-cols-2"><label class="field"><span>初始密码 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input type="password" name="password" required minlength="12" autocomplete="new-password"><span class="hint">至少 12 位。通过安全方式交给账号使用者。</span>@if(old('_form') === $formKey) @error('password')<span class="field-error">{{ $message }}</span>@enderror @endif</label><label class="field"><span>确认密码 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">@if(old('_form') === $formKey) @error('password_confirmation')<span class="field-error">{{ $message }}</span>@enderror @endif</label></div>
    @endunless
    <div class="grid gap-3 sm:grid-cols-2">
        <label class="field"><span>医生姓名 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input name="full_name" value="{{ $values['full_name'] ?? '' }}" required maxlength="64">@if(old('_form') === $formKey) @error('full_name')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>职称 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><input name="title" value="{{ $values['title'] ?? '' }}" required maxlength="32">@if(old('_form') === $formKey) @error('title')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>所属科室 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><select name="department_id" required><option value="">请选择</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)($values['department_id'] ?? '') === (string)$department->id)>{{ $department->name }}{{ $department->status === 'DISABLED' ? '（已停用）' : '' }}</option>@endforeach</select>@if(old('_form') === $formKey) @error('department_id')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
        <label class="field"><span>医生资源状态 <span aria-hidden="true" class="req">*</span><span class="sr-only">（必填）</span></span><select name="status" required><option value="ACTIVE" @selected(($values['status'] ?? 'ACTIVE') === 'ACTIVE')>启用</option><option value="DISABLED" @selected(($values['status'] ?? '') === 'DISABLED')>停用</option></select>@if(old('_form') === $formKey) @error('status')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    </div>
    <label class="field">擅长领域<input name="specialty" value="{{ $values['specialty'] ?? '' }}" maxlength="255">@if(old('_form') === $formKey) @error('specialty')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    <label class="field">医生简介<textarea name="introduction" rows="2" maxlength="16000">{{ $values['introduction'] ?? '' }}</textarea>@if(old('_form') === $formKey) @error('introduction')<span class="field-error">{{ $message }}</span>@enderror @endif</label>
    <p class="hint">有排班的医生暂不支持转科。停用医生资源不删除排班，也不改变其登录账号状态；本人仍可查看历史安排。</p>
    <button class="btn" type="submit">{{ $record ? '保存医生' : '创建医生及账号' }}</button>
</form>
