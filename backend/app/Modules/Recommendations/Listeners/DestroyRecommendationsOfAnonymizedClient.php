<?php

namespace App\Modules\Recommendations\Listeners;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;
use Illuminate\Support\Str;

/**
 * account.user.anonymized {user_id}: recommendations addressed to the client may describe their state, so they
 * are destroyed with the account (SEQ-22). Files stay with their author. Only the fact goes to the audit journal.
 */
class DestroyRecommendationsOfAnonymizedClient implements DomainEventListener
{
    public function handle(DomainEvent $event): void
    {
        $userId = $event->payload['user_id'] ?? null;
        if (! Str::isUuid($userId)) {
            return;
        }

        $count = Recommendation::where('client_id', $userId)->delete();
        if ($count > 0) {
            Audit::log('X-11', 'reco.recommendations.destroyed', User::withTrashed()->find($userId), ['count' => $count, 'reason' => 'account_anonymized']);
        }
    }
}
