<?php

namespace App\Payments;

interface PaymentGateway
{
    /** Produce a server-side demonstration event, never a client-supplied paid status. */
    public function payment(object $order, string $scenario): array;

    public function query(object $order): array;

    public function close(object $order): string;

    public function refund(object $order, object $refund, string $scenario): array;
}
