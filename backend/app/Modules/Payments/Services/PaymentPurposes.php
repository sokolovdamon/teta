<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentPurposeHandler;

/**
 * Routing of payment results by purpose. PAY registers "session" (autocharge and payment of a failed charge),
 * "booking" (late booking paid at once) and "certificate". Other modules register their own purposes
 * (supervision, event, b2b_invoice) and start payments with PaymentService::startPayerPayment().
 * Handlers run inside the transaction that moves the payment to its final status.
 */
class PaymentPurposes
{
    /** @var array<string, class-string<PaymentPurposeHandler>> */
    private static array $handlers = [];

    /** @param  class-string<PaymentPurposeHandler>  $handler */
    public static function register(string $purpose, string $handler): void
    {
        self::$handlers[$purpose] = $handler;
    }

    public static function for(string $purpose): ?PaymentPurposeHandler
    {
        $class = self::$handlers[$purpose] ?? null;

        return $class ? app($class) : null;
    }
}
