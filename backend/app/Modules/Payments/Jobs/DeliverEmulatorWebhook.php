<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Gateway\Emulator\EmulatorWebhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Queued delivery of an emulator webhook: in-process (queue mode) or as a real HTTP POST (http mode). Retries like a provider. */
class DeliverEmulatorWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $body, public string $signature, public ?string $url = null) {}

    public function handle(EmulatorWebhooks $webhooks): void
    {
        if ($this->url) {
            $response = Http::withHeaders(['X-Emulator-Signature' => $this->signature])
                ->withBody($this->body, 'application/json')
                ->timeout(10)
                ->post($this->url);
            if ($response->serverError()) {
                throw new RuntimeException('Webhook endpoint answered '.$response->status());
            }

            return;
        }

        [$status] = $webhooks->deliver($this->body, $this->signature);
        if ($status >= 500) {
            throw new RuntimeException('Webhook processing failed');
        }
    }
}
