<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case PendingPayment = 'PENDING_PAYMENT';
    case Confirmed = 'CONFIRMED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Completed = 'COMPLETED';
}
