<?php

namespace App\Modules\Payments\Gateway;

/**
 * Routing of gateway webhooks to modules by event type prefix. PAY registers "payment.", "refund.", "binding.";
 * PAYOUT registers "payout.". The webhook controller writes the event to the inbox and then calls dispatch().
 */
class WebhookHandlers
{
    /** @var array<string, callable(WebhookEvent): void> */
    private static array $handlers = [];

    /** @param  callable(WebhookEvent): void  $handler */
    public static function register(string $typePrefix, callable $handler): void
    {
        self::$handlers[$typePrefix] = $handler;
    }

    public static function dispatch(WebhookEvent $event): bool
    {
        foreach (self::$handlers as $prefix => $handler) {
            if (str_starts_with($event->type, $prefix)) {
                $handler($event);

                return true;
            }
        }

        return false;
    }
}
