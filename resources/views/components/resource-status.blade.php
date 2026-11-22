@props(['value', 'variant' => 'badge'])
@php
    $labels = [
        'ACTIVE' => '启用', 'DISABLED' => '停用', 'DRAFT' => '草稿', 'OPEN' => '开放',
        'SUSPENDED' => '停诊', 'CLOSED' => '关闭', 'PENDING_PAYMENT' => '待支付',
        'CONFIRMED' => '已确认', 'CANCELLED' => '已取消', 'EXPIRED' => '已过期',
        'COMPLETED' => '已完成', 'UNPAID' => '未支付', 'PAID' => '模拟已支付',
        'REFUND_PENDING' => '退款处理中', 'REFUNDED' => '模拟已退款',
        'PENDING' => '退款待处理', 'FAILED' => '退款失败', 'SUCCEEDED' => '退款成功',
    ];
    $tones = [
        'ACTIVE' => 'success', 'OPEN' => 'success', 'CONFIRMED' => 'success',
        'PAID' => 'success', 'COMPLETED' => 'success', 'REFUNDED' => 'success',
        'SUCCEEDED' => 'success', 'PENDING_PAYMENT' => 'warning', 'UNPAID' => 'warning',
        'REFUND_PENDING' => 'warning', 'PENDING' => 'warning',
        'FAILED' => 'danger', 'SUSPENDED' => 'danger',
    ];
@endphp
@if($variant === 'text')
<span class="status-text status-text--{{ $tones[$value] ?? 'neutral' }}">{{ $labels[$value] ?? '未知状态' }}</span>
@else
<span class="status-badge status-badge--{{ $tones[$value] ?? 'neutral' }}">{{ $labels[$value] ?? '未知状态' }}</span>
@endif
