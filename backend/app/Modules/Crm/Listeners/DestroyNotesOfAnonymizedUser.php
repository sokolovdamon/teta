<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Services\NoteRetention;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;
use Illuminate\Support\Str;

/** account.user.anonymized {user_id}: private notes by and about the user are destroyed; only the fact is audited. */
class DestroyNotesOfAnonymizedUser implements DomainEventListener
{
    public function __construct(private NoteRetention $retention) {}

    public function handle(DomainEvent $event): void
    {
        $userId = $event->payload['user_id'] ?? null;
        if (Str::isUuid($userId)) {
            $this->retention->destroyForUser($userId);
        }
    }
}
