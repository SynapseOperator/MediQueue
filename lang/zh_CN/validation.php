<?php

return [
    'required' => ':attribute不能为空。',
    'required_if' => '当前:other为:value时，必须填写:attribute。',
    'string' => ':attribute必须为文本。',
    'integer' => ':attribute必须为整数。',
    'numeric' => ':attribute必须为有效数字。',
    'regex' => ':attribute格式不正确。',
    'in' => ':attribute不是可接受的选项。',
    'exists' => '所选:attribute不存在。',
    'unique' => ':attribute已被使用。',
    'confirmed' => '两次输入的:attribute不一致。',
    'date_format' => ':attribute格式不正确（要求 :format）。',
    'after_or_equal' => ':attribute不能早于:date。',
    'before_or_equal' => ':attribute不能晚于:date。',
    'prohibited' => '不能提交或修改:attribute。',
    'max' => ['string' => ':attribute不能超过:max个字符。', 'numeric' => ':attribute不能大于:max。'],
    'min' => ['string' => ':attribute至少需要:min个字符。', 'numeric' => ':attribute不能小于:min。'],
    'gt' => ['numeric' => ':attribute必须大于:value。'],
    'attributes' => [
        'username' => '用户名', 'password' => '密码', 'full_name' => '姓名', 'phone' => '联系方式',
        'code' => '科室编码', 'name' => '科室名称', 'status' => '状态', 'title' => '职称',
        'department_id' => '科室', 'doctor_id' => '医生', 'schedule_id' => '排班',
        'work_date' => '业务日期', 'session_code' => '班次', 'starts_at' => '开始时间', 'ends_at' => '结束时间',
        'capacity' => '容量', 'fee_amount' => '挂号费', 'suspend_reason' => '停诊原因', 'date' => '日期',
        'introduction' => '介绍', 'specialty' => '擅长领域', 'role' => '角色', 'user_id' => '用户编号', 'patient_id' => '患者编号',
    ],
];
