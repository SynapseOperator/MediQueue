@props(['value'])
@php($labels = ['ACTIVE' => '启用', 'DISABLED' => '停用', 'DRAFT' => '草稿', 'OPEN' => '开放', 'SUSPENDED' => '停诊', 'CLOSED' => '关闭', 'PENDING_PAYMENT' => '待支付', 'CONFIRMED' => '已确认', 'CANCELLED' => '已取消', 'EXPIRED' => '已过期', 'COMPLETED' => '已完成', 'UNPAID' => '待支付', 'PAID' => '模拟已支付', 'REFUND_PENDING' => '退款处理中', 'REFUNDED' => '模拟已退款', 'PENDING' => '待处理', 'FAILED' => '失败', 'SUCCEEDED' => '成功'])
<span class="status-badge">{{ $labels[$value] ?? $value }} <span class="ml-1 font-normal">{{ $value }}</span></span>
