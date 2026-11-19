@php
    if (str_starts_with($message, '模拟微信支付结果：')) {
        $message = match ((string) str($message)->after('：')->before('（')) {
            'CONFIRMED' => '模拟支付成功，预约已确认。',
            'COMPLETED' => '本次预约已完成。',
            'CANCELLED' => '本次预约已取消。',
            'EXPIRED' => '付款已超时，预约已过期。',
            'DECLINED' => '模拟支付被拒绝，请查看订单后重试。',
            default => '请查看当前预约的付款状态。',
        };
    } elseif (str_starts_with($message, '模拟支付结果未知')) {
        $message = '模拟支付结果未知，请先刷新本页查看付款状态。';
    } elseif (str_starts_with($message, '模拟退款结果：')) {
        $message = match ((string) str($message)->after('：')->before('（')) {
            'SUCCEEDED' => '模拟退款成功。',
            'FAILED' => '模拟退款失败，请查看原因后重试。',
            default => '模拟退款结果待确认，请查看退款记录。',
        };
    }
@endphp
<p class="status-message" role="status">{{ $message }}</p>
