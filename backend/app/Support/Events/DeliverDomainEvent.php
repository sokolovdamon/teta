<?php

namespace App\Support\Events;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliverDomainEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $eventId)
    {
        $this->onQueue('outbox');
    }

    public function handle(): void
    {
        $event = DomainEvent::find($this->eventId);
        if (! $event) {
            return;
        }

        foreach (Outbox::listenersFor($event->name) as $listenerClass) {
            $already = DB::table('processed_domain_events')
                ->where(['event_id' => $event->id, 'listener' => $listenerClass])
                ->exists();
            if ($already) {
                continue;
            }

            try {
                DB::transaction(function () use ($event, $listenerClass) {
                    app($listenerClass)->handle($event);
                    DB::table('processed_domain_events')->insert([
                        'event_id' => $event->id,
                        'listener' => $listenerClass,
                        'processed_at' => now(),
                    ]);
                });
            } catch (Throwable $e) {
                $event->forceFill(['attempts' => $event->attempts + 1, 'last_error' => $e->getMessage()])->save();
                throw $e;
            }
        }

        $event->forceFill(['dispatched_at' => now()])->save();
    }
}
