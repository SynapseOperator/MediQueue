@props(['value'])
<span class="inline-block rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">{{ ['ACTIVE' => '启用', 'DISABLED' => '停用', 'DRAFT' => '草稿', 'OPEN' => '开放', 'SUSPENDED' => '停诊', 'CLOSED' => '关闭'][$value] ?? $value }}</span>
