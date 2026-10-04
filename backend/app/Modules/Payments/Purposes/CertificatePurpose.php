<?php

namespace App\Modules\Payments\Purposes;

use App\Modules\Payments\Contracts\PaymentPurposeHandler;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\GiftCertificateService;

/** Purpose "certificate": sale of a gift certificate (ST-18). */
class CertificatePurpose implements PaymentPurposeHandler
{
    public function __construct(private GiftCertificateService $certificates) {}

    public function succeeded(Payment $payment): void
    {
        $this->certificates->onPaid($payment);
    }

    public function declined(Payment $payment): void
    {
        $this->certificates->onDeclined($payment);
    }

    public function unknown(Payment $payment): void {}
}
