<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Gateway\InvalidWebhook;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\WebhookHandlers;
use App\Modules\Payments\Models\WebhookInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gateway webhooks (BR-PAY-06): verify the signature, write the event to webhook_inbox (unique external id per
 * provider), then route it to the owning module through WebhookHandlers. A duplicate or late event is answered
 * with 2xx and not processed again; a failed one stays in the inbox unprocessed and is processed on redelivery.
 */
class WebhookProcessor
{
    /** @return array{0: int, 1: array<string, mixed>} [http status, body] */
    public function receive(PaymentGateway $gateway, Request $request): array
    {
        try {
            $event = $gateway->parseWebhook($request);
        } catch (InvalidWebhook $e) {
            WebhookInbox::create([
                'provider' => $gateway->name(),
                'external_id' => 'invalid:'.Str::uuid(),
                'event_type' => 'invalid',
                'payload' => ['raw' => mb_substr((string) $request->getContent(), 0, 2000)],
                'signature_valid' => false,
                'error' => $e->getMessage(),
            ]);

            return [400, ['message' => $e->getMessage()]];
        }

        WebhookInbox::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'provider' => $gateway->name(),
            'external_id' => $event->externalId,
            'event_type' => $event->type,
            'payload' => json_encode($event->payload, JSON_UNESCAPED_UNICODE),
            'signature_valid' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            return DB::transaction(function () use ($gateway, $event) {
                $row = WebhookInbox::where('provider', $gateway->name())->where('external_id', $event->externalId)->lockForUpdate()->firstOrFail();
                if ($row->processed_at !== null) {
                    return [200, ['ok' => true, 'duplicate' => true]];
                }
                $handled = WebhookHandlers::dispatch($event);
                $row->forceFill(['processed_at' => now(), 'error' => $handled ? null : 'no handler for '.$event->type])->save();

                return [200, ['ok' => true, 'handled' => $handled]];
            });
        } catch (Throwable $e) {
            Log::error('Webhook processing failed', ['type' => $event->type, 'id' => $event->externalId, 'error' => $e->getMessage()]);
            WebhookInbox::where('provider', $gateway->name())->where('external_id', $event->externalId)
                ->update(['error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);

            return [500, ['message' => 'Webhook processing failed']];
        }
    }
}
