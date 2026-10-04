<?php

namespace App\Modules\Booking\Listeners;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\CancellationService;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;

/**
 * BR-CANC-09, BR-ACC-17: a blocked client or psychologist, a rejected qualification or a blocked work status —
 * every upcoming (not started) session is cancelled by the platform; paid money goes to the client's balance.
 * Consumes account.user.blocked (aggregate: user), psy.qualification.rejected and psy.work_status.blocked
 * (aggregate: psychologist; also the default state-machine names psy.rejected and psy.blocked). Idempotent.
 */
class CancelSessionsOfBlockedParticipant implements DomainEventListener
{
    public function __construct(private CancellationService $cancellations) {}

    public function handle(DomainEvent $event): void
    {
        $sessions = TherapySession::query()
            ->whereIn('status', [TherapySession::BOOKED, TherapySession::PAID])
            ->where('starts_at', '>', now());

        if ($event->aggregate_type === (new User)->getMorphClass()) {
            $user = User::withTrashed()->find($event->aggregate_id);
            if (! $user) {
                return;
            }
            $psychologistId = Psychologist::withTrashed()->where('user_id', $user->id)->value('id');
            $sessions->where(fn ($q) => $q->where('client_id', $user->id)
                ->when($psychologistId, fn ($w) => $w->orWhere('psychologist_id', $psychologistId)));
            $kind = 'block';
            $reason = 'Участник сессии заблокирован';
        } elseif ($event->aggregate_type === (new Psychologist)->getMorphClass()) {
            $sessions->where('psychologist_id', $event->aggregate_id);
            $kind = 'block';
            $reason = str_contains($event->name, 'qualification') || $event->name === 'psy.rejected'
                ? 'Квалификация психолога не подтверждена'
                : 'Психолог не может проводить сессии';
        } else {
            return;
        }

        foreach ($sessions->pluck('id') as $id) {
            $this->cancellations->cancelBySystem(TherapySession::findOrFail($id), $kind, $reason, $event->actor_id);
        }
    }
}
