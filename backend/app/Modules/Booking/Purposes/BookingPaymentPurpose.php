<?php

namespace App\Modules\Booking\Purposes;

use App\Modules\Booking\Services\BookingService;
use App\Modules\Payments\Contracts\PaymentPurposeHandler;
use App\Modules\Payments\Models\Payment;

/** Purpose "booking": a late booking paid at once; the session is created only after a successful payment (BR-BOOK-04). */
class BookingPaymentPurpose implements PaymentPurposeHandler
{
    public function __construct(private BookingService $booking) {}

    public function succeeded(Payment $payment): void
    {
        $this->booking->completeIntent($payment);
    }

    public function declined(Payment $payment): void
    {
        $this->booking->failIntent($payment);
    }

    public function unknown(Payment $payment): void {}
}
