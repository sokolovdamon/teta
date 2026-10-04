<?php

namespace App\Modules\Payments\Gateway;

final class RefundRequest
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $gatewayPaymentId,
        public readonly int $amount,
        public readonly ?ReceiptData $receipt = null,
    ) {}
}
