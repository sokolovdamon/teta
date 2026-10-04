<?php

namespace App\Modules\Payments\Gateway;

/** Receipt data for 54-ФЗ: PREPAYMENT_FULL for B2C, CREDIT_PAYMENT for B2B (DEC-22); agent attributes are added by the adapter. */
final class ReceiptData
{
    /** @param  list<array{name: string, amount: int, quantity?: int, vat?: string}>  $items */
    public function __construct(
        public readonly string $calculationMethod,
        public readonly array $items,
        public readonly ?string $customerEmail = null,
    ) {}
}
