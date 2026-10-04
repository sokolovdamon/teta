<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Crm\Models\ClientCard;
use App\Modules\Crm\Models\PsychologistNote;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Retention of private notes (P-NOTES-RETENTION, years) after the work ends, and destruction on account
 * deletion (SEQ-22, BR-RBAC-07). The audit journal gets only the fact and the number of notes, never content.
 *
 * The work ends at the card's access_until («работа завершена» or change of psychologist) or, without a mark,
 * at the end of the last session when nothing is booked any more.
 */
class NoteRetention
{
    public function __construct(private ClientRelationship $relationship) {}

    /** @return array{pairs: int, notes: int} */
    public function purgeExpired(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $threshold = $now->subYears(Settings::int('P-NOTES-RETENTION'));
        $stats = ['pairs' => 0, 'notes' => 0];

        $pairs = PsychologistNote::query()->select(['psychologist_id', 'client_id'])->distinct()->get();
        foreach ($pairs as $pair) {
            $ended = $this->workEndedAt($pair->psychologist_id, $pair->client_id, $now);
            if ($ended === null || $ended->greaterThanOrEqualTo($threshold)) {
                continue;
            }

            DB::transaction(function () use ($pair, $now, &$stats) {
                $count = PsychologistNote::where('psychologist_id', $pair->psychologist_id)->where('client_id', $pair->client_id)->delete();
                $card = ClientCard::for($pair->psychologist_id, $pair->client_id);
                $card->forceFill(['notes_purged_at' => $now])->save();
                Audit::log('PRO-05', 'crm.notes.purged', $card, ['count' => $count, 'reason' => 'retention']);
                $stats['pairs']++;
                $stats['notes'] += $count;
            });
        }

        return $stats;
    }

    /** End of work for the pair, or null when the work goes on. */
    public function workEndedAt(string $psychologistId, string $clientId, CarbonImmutable $now): ?CarbonImmutable
    {
        $until = $this->relationship->accessUntil($psychologistId, $clientId);
        if ($until !== null) {
            return $until;
        }

        $sessions = $this->relationship->sessionsOfPair($psychologistId, $clientId);
        $hasUpcoming = (clone $sessions)->whereIn('status', TherapySession::ACTIVE_STATUSES)->where('ends_at', '>', $now)->exists();
        if ($hasUpcoming) {
            return null;
        }
        $last = $this->relationship->qualifying(clone $sessions)->max('ends_at');

        return $last === null ? null : CarbonImmutable::parse($last, 'UTC');
    }

    /** Account deletion: notes written by a psychologist and notes about a client are destroyed (SEQ-22). */
    public function destroyForUser(string $userId): int
    {
        $psychologistIds = Psychologist::withTrashed()->where('user_id', $userId)->pluck('id');
        $count = PsychologistNote::query()
            ->where(fn ($q) => $q->where('client_id', $userId)->orWhereIn('psychologist_id', $psychologistIds))
            ->delete();

        if ($count > 0) {
            $user = User::withTrashed()->find($userId);
            Audit::log('X-11', 'crm.notes.destroyed', $user, ['count' => $count, 'reason' => 'account_anonymized'], userId: null);
        }

        return $count;
    }
}
