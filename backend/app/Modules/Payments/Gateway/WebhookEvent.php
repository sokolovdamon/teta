<?php

namespace App\Modules\Payments\Gateway;

/**
 * Parsed webhook. Types: payment.succeeded | payment.declined | refund.succeeded | refund.failed
 * | payout.paid | payout.rejected | binding.succeeded | binding.declined.
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $type,
        public readonly string $idempotencyKey,
        public readonly GatewayOperation $operation,
        public readonly array $payload = [],
    ) {}
}
