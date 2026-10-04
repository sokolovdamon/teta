<?php

namespace App\Modules\Crm\Listeners;

use App\Models\User;
use App\Modules\Crm\Services\ClientRelationship;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * book.psychologist.changed {client_id, psychologist_id (the psychologist the client leaves), at} — SEQ-04, DM-08:
 * the previous psychologist keeps seeing the diary only up to the change moment. Idempotent: closing the window
 * again with the same moment changes nothing.
 */
class CloseAccessOnPsychologistChange implements DomainEventListener
{
    public function __construct(private ClientRelationship $relationship) {}

    public function handle(DomainEvent $event): void
    {
        $clientId = $event->payload['client_id'] ?? null;
        $psychologistId = $event->payload['psychologist_id'] ?? null;
        if (! Str::isUuid($clientId) || ! Str::isUuid($psychologistId)) {
            return;
        }
        if (! Psychologist::withTrashed()->whereKey($psychologistId)->exists() || ! User::withTrashed()->whereKey($clientId)->exists()) {
            return;
        }

        try {
            $at = CarbonImmutable::parse($event->payload['at'] ?? $event->occurred_at)->utc();
        } catch (Throwable) {
            $at = CarbonImmutable::parse($event->occurred_at)->utc();
        }

        $this->relationship->close($psychologistId, $clientId, 'changed', $at);
    }
}
