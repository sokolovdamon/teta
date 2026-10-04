<?php

namespace App\Support\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Transactional outbox (technical architecture: outbox_flow).
 *
 * Modules write a domain event in the same DB transaction as their change; after commit the event is
 * delivered through the "outbox" queue to listeners registered with Outbox::listen(). Listeners must be
 * idempotent: delivery is at-least-once, and processed_domain_events guards against repeats.
 */
class Outbox
{
    /** @var array<string, list<class-string<DomainEventListener>>> */
    private static array $listeners = [];

    /** @param  class-string<DomainEventListener>  $listener */
    public static function listen(string $eventName, string $listener): void
    {
        self::$listeners[$eventName] ??= [];
        if (! in_array($listener, self::$listeners[$eventName], true)) {
            self::$listeners[$eventName][] = $listener;
        }
    }

    /** @return list<class-string<DomainEventListener>> */
    public static function listenersFor(string $eventName): array
    {
        $exact = self::$listeners[$eventName] ?? [];
        $wildcard = [];
        foreach (self::$listeners as $pattern => $list) {
            if (str_ends_with($pattern, '.*') && str_starts_with($eventName, substr($pattern, 0, -1))) {
                $wildcard = [...$wildcard, ...$list];
            }
        }

        return array_values(array_unique([...$exact, ...$wildcard]));
    }

    /** @param  array<string, mixed>  $payload */
    public static function record(string $name, ?Model $aggregate = null, array $payload = [], ?string $actorId = null): DomainEvent
    {
        $event = DomainEvent::create([
            'name' => $name,
            'aggregate_type' => $aggregate?->getMorphClass(),
            'aggregate_id' => $aggregate?->getKey(),
            'payload' => $payload,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);

        DB::afterCommit(fn () => DeliverDomainEvent::dispatch($event->id));

        return $event;
    }
}
