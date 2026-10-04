<?php

namespace App\Modules\Diary\Listeners;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Diary\Services\DiaryService;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;
use Illuminate\Support\Str;

/** account.user.anonymized {user_id}: the diary is destroyed (SEQ-22); the audit journal gets only the fact. */
class DestroyDiaryOfAnonymizedUser implements DomainEventListener
{
    public function __construct(private DiaryService $diary) {}

    public function handle(DomainEvent $event): void
    {
        $userId = $event->payload['user_id'] ?? null;
        if (! Str::isUuid($userId)) {
            return;
        }

        $count = $this->diary->destroyForUser($userId);
        if ($count > 0) {
            Audit::log('X-11', 'diary.entries.destroyed', User::withTrashed()->find($userId), ['count' => $count, 'reason' => 'account_anonymized']);
        }
    }
}
