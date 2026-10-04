<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Models\Payment;

/** Reaction of the owning module to the final result of a payment (ST-03). Must be idempotent. */
interface PaymentPurposeHandler
{
    public function succeeded(Payment $payment): void;

    public function declined(Payment $payment): void;

    /** The gateway did not answer (timeout, 5xx): the status will be queried before any retry. */
    public function unknown(Payment $payment): void;
}
