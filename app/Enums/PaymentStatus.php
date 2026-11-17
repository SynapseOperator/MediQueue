<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'UNPAID';
    case Paid = 'PAID';
    case Closed = 'CLOSED';
    case RefundPending = 'REFUND_PENDING';
    case Refunded = 'REFUNDED';
}
