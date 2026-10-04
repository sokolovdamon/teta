<?php

namespace App\Modules\Payouts\Jobs;

use App\Modules\Payouts\Models\PayoutRegistry;
use App\Modules\Payouts\Services\PayoutRegistryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends the lines of an approved registry through the gateway (queue "payouts", SEQ-08). */
class SendRegistryPayouts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public string $registryId)
    {
        $this->onQueue('payouts');
    }

    public function handle(PayoutRegistryService $service): void
    {
        $registry = PayoutRegistry::find($this->registryId);
        if ($registry) {
            $service->sendRegistry($registry);
        }
    }
}
