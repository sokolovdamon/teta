<?php

namespace App\Modules\Payments\Gateway;

final class PaymentRequest
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly int $amount,
        public readonly string $description,
        public readonly string $returnUrl,
        public readonly ?ReceiptData $receipt = null,
        public readonly bool $saveCard = false,
        public readonly ?string $userId = null,
    ) {}
}
