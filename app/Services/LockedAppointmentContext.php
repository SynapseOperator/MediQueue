<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use stdClass;

/** The rows and authoritative time read under one patient-first transaction. */
final readonly class LockedAppointmentContext
{
    public AppointmentStatus $appointmentStatus;

    public PaymentStatus $paymentStatus;

    public ?RefundStatus $refundStatus;

    public function __construct(
        public stdClass $patient,
        public ?stdClass $actor,
        public stdClass $department,
        public stdClass $doctor,
        public stdClass $doctorAccount,
        public stdClass $schedule,
        public stdClass $slot,
        public stdClass $appointment,
        public stdClass $order,
        public ?stdClass $refund,
        public string $now,
    ) {
        $this->appointmentStatus = AppointmentStatus::from($appointment->status);
        $this->paymentStatus = PaymentStatus::from($order->status);
        $this->refundStatus = $refund === null ? null : RefundStatus::from($refund->status);
    }
}
