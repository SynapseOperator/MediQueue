<?php

namespace App\Payments;

use Illuminate\Validation\ValidationException;

class MockWeChatGateway implements PaymentGateway
{
    public function payment(object $order, string $scenario): array
    {
        $this->ensureEnabled();

        return $this->query($order) + ['result' => match ($scenario) {
            'success' => 'PAID', 'declined' => 'DECLINED', 'unknown' => 'UNKNOWN',
        }];
    }

    public function query(object $order): array
    {
        return ['order_no' => $order->order_no, 'amount' => $order->amount,
            'currency' => $order->currency, 'channel' => 'MOCK_WECHAT', 'status' => $order->status];
    }

    public function close(object $order): string
    {
        if (! in_array($order->status, ['UNPAID', 'CLOSED'], true)) {
            throw ValidationException::withMessages(['appointment' => '已付款订单不能作为未付款订单关闭。']);
        }

        // Mock settlement has no external ledger or network call. The application persists the close in its transaction.
        return 'CLOSED';
    }

    public function refund(object $order, object $refund, string $scenario): array
    {
        $this->ensureEnabled();

        return $this->query($order) + ['refund_no' => $refund->refund_no, 'refund_amount' => $refund->amount,
            'result' => match ($scenario) {
                'success' => 'SUCCEEDED', 'failed' => 'FAILED', 'unknown' => 'PENDING',
            }];
    }

    private function ensureEnabled(): void
    {
        abort_unless(app()->environment(['local', 'demo', 'testing']) && config('mediqueue.mock_payments_enabled'), 403, '模拟支付仅在显式启用的演示环境开放。');
    }
}
