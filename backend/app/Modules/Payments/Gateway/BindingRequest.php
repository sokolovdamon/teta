<?php

namespace App\Modules\Payments\Gateway;

final class BindingRequest
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $userId,
        public readonly string $returnUrl,
        public readonly int $verificationAmount = 100,
    ) {}
}
