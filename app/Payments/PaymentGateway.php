<?php

namespace App\Payments;

use stdClass;

interface PaymentGateway
{
    /**
     * Produce a server-side demonstration event, never a client-supplied paid status.
     *
     * @param  'success'|'declined'|'unknown'  $scenario
     */
    public function payment(stdClass $order, string $scenario): array;

    public function query(stdClass $order): array;

    public function close(stdClass $order): string;

    /** @param 'success'|'failed'|'unknown' $scenario */
    public function refund(stdClass $order, stdClass $refund, string $scenario): array;
}
