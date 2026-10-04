<?php

namespace App\Modules\Payments\Purposes;

use App\Modules\Payments\Contracts\PaymentPurposeHandler;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\ChargeService;

/** Purpose "session": autocharge attempts by token and payments of a failed charge with another card (ST-02). */
class SessionChargePurpose implements PaymentPurposeHandler
{
    public function __construct(private ChargeService $charges) {}

    public function succeeded(Payment $payment): void
    {
        $this->charges->onPaymentSucceeded($payment);
    }

    public function declined(Payment $payment): void
    {
        $this->charges->onPaymentDeclined($payment);
    }

    public function unknown(Payment $payment): void
    {
        $this->charges->onPaymentUnknown($payment);
    }
}
