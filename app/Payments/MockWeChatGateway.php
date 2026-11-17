<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use Illuminate\Validation\ValidationException;
use stdClass;

class MockWeChatGateway implements PaymentGateway
{
    /** @param 'success'|'declined'|'unknown' $scenario */
    public function payment(stdClass $order, string $scenario): array
    {
        $this->ensureEnabled();

        return $this->query($order) + ['result' => match ($scenario) {
            'success' => PaymentStatus::Paid->value, 'declined' => 'DECLINED', 'unknown' => 'UNKNOWN',
        }];
    }

    public function query(stdClass $order): array
    {
        return ['order_no' => $order->order_no, 'amount' => $order->amount,
            'currency' => $order->currency, 'channel' => 'MOCK_WECHAT', 'status' => $order->status];
    }

    public function close(stdClass $order): string
    {
        if (! in_array($order->status, [PaymentStatus::Unpaid->value, PaymentStatus::Closed->value], true)) {
            throw ValidationException::withMessages(['appointment' => '已付款订单不能作为未付款订单关闭。']);
        }

        // Mock settlement has no external ledger or network call. The application persists the close in its transaction.
        return PaymentStatus::Closed->value;
    }

    /** @param 'success'|'failed'|'unknown' $scenario */
    public function refund(stdClass $order, stdClass $refund, string $scenario): array
    {
        $this->ensureEnabled();

        return $this->query($order) + ['refund_no' => $refund->refund_no, 'refund_amount' => $refund->amount,
            'result' => match ($scenario) {
                'success' => RefundStatus::Succeeded->value, 'failed' => RefundStatus::Failed->value, 'unknown' => RefundStatus::Pending->value,
            }];
    }

    private function ensureEnabled(): void
    {
        abort_unless(app()->environment(['local', 'demo', 'testing']) && config('mediqueue.mock_payments_enabled'), 403, '模拟支付仅在显式启用的演示环境开放。');
    }
}
