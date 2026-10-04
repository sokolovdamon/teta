<?php

namespace App\Modules\Payments\Gateway\Emulator;

use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Jobs\DeliverEmulatorWebhook;
use App\Modules\Payments\Services\WebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sends signed webhooks of the emulator to the platform's own endpoint POST /api/v1/payments/webhooks/emulator.
 * The event id is deterministic (operation + type), so a resend of the same event is a duplicate for the inbox.
 * Delivery mode — config payments.emulator.webhook_delivery (sync | queue | http).
 */
class EmulatorWebhooks
{
    public static function sign(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, (string) config('payments.emulator.secret'));
    }

    /** @return array<string, mixed> */
    public static function payload(EmulatorOperation $op, string $type): array
    {
        return [
            'id' => 'evt_'.$op->id.'_'.str_replace('.', '_', $type),
            'type' => $type,
            'idempotency_key' => $op->idempotency_key,
            'created_at' => now()->toIso8601String(),
            'object' => [
                'id' => $op->id,
                'kind' => $op->kind,
                'status' => $op->status,
                'amount' => $op->amount,
                'refunded_amount' => $op->refunded_amount,
                'parent_id' => $op->parent_id,
                'error_code' => $op->error_code,
                'error_category' => $op->error_category,
                'card' => $op->card(),
                'card_mask' => $op->card_mask,
                'receipt' => $op->fiscal,
            ],
        ];
    }

    public function send(EmulatorOperation $op, string $type): void
    {
        $body = (string) json_encode(self::payload($op, $type), JSON_UNESCAPED_UNICODE);
        $signature = self::sign($body);

        DB::afterCommit(function () use ($op, $body, $signature) {
            $op->forceFill(['webhook_sent_at' => now()])->save();
            match (config('payments.emulator.webhook_delivery', 'sync')) {
                'queue' => DeliverEmulatorWebhook::dispatch($body, $signature)->onQueue('webhooks'),
                'http' => DeliverEmulatorWebhook::dispatch($body, $signature, $this->url())->onQueue('webhooks'),
                default => $this->deliver($body, $signature),
            };
        });
    }

    /**
     * In-process delivery: the request goes through exactly the same signature check, inbox and handlers
     * as an HTTP call from a real provider.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function deliver(string $body, string $signature): array
    {
        $request = Request::create('/api/v1/payments/webhooks/emulator', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EMULATOR_SIGNATURE' => $signature,
        ], content: $body);

        return app(WebhookProcessor::class)->receive(app(PaymentGateway::class), $request);
    }

    private function url(): string
    {
        return (string) (config('payments.emulator.webhook_url') ?: rtrim((string) config('app.url'), '/').'/api/v1/payments/webhooks/emulator');
    }
}
