<?php

namespace App\Modules\Payments\Gateway;

final class PayoutRequest
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $token,
        public readonly int $amount,
        public readonly string $description,
    ) {}
}
