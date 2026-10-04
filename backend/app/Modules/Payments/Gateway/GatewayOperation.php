<?php

namespace App\Modules\Payments\Gateway;

/** Result of a gateway call. */
final class GatewayOperation
{
    public const SUCCEEDED = 'succeeded';

    public const DECLINED = 'declined';

    public const PENDING = 'pending';

    public const REQUIRES_ACTION = 'requires_action';

    public const UNKNOWN = 'unknown';

    /**
     * @param  string|null  $errorCategory  retry | no_retry | new_card (BR-PAY-05)
     * @param  array{token: string, mask: string, brand: string, exp_month: int, exp_year: int}|null  $card
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $gatewayId = null,
        public readonly ?string $confirmationUrl = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorCategory = null,
        public readonly ?array $card = null,
        public readonly array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
