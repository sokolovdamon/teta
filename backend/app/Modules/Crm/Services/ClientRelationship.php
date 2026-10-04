<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Crm\Models\ClientCard;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Hard access restrictions between a psychologist and a client (TZ v2, section 8; DEC-41; DM-08; BR-RBAC-08).
 * They are checked in code and never granted through RBAC: admins and super admins have no way around them.
 *
 *  - Client list and card: the client has a session with this psychologist, except cancellations that were never paid.
 *  - Diary: the client is booked with this psychologist or had a held session with them; after a change of
 *    psychologist or the «работа завершена» mark only entries before that moment are visible, until the client
 *    books this psychologist again.
 */
class ClientRelationship
{
    public const CANCELLED = [TherapySession::CANCELLED_BY_CLIENT, TherapySession::CANCELLED_BY_PSY, TherapySession::CANCELLED_BY_SYSTEM];

    /** "Записан или проходил сессии" (DEC-41). */
    public const DIARY_STATUSES = [TherapySession::BOOKED, TherapySession::PAID, TherapySession::IN_PROGRESS, TherapySession::HELD];

    /** The psychologist profile of a user, or 403: CRM sections exist only for psychologists. */
    public static function psychologistOf(User $user): Psychologist
    {
        $psychologist = Psychologist::where('user_id', $user->id)->first();
        abort_unless($psychologist !== null, 403, 'Раздел доступен только психологам.');

        return $psychologist;
    }

    /** Sessions of the pair, the client being the booker or the second participant of a pair session. */
    public function sessionsOfPair(string $psychologistId, string $clientId): Builder
    {
        return TherapySession::query()
            ->where('psychologist_id', $psychologistId)
            ->where(fn (Builder $q) => $q->where('client_id', $clientId)->orWhere('partner_user_id', $clientId));
    }

    /** Sessions that put the client into the psychologist's list. */
    public function qualifying(Builder|QueryBuilder $query, string $prefix = ''): Builder|QueryBuilder
    {
        return $query->where(fn ($q) => $q->whereNotIn($prefix.'status', self::CANCELLED)->orWhereNotNull($prefix.'paid_at'));
    }

    public function isClientOf(Psychologist $psychologist, string $clientId): bool
    {
        return $this->qualifying($this->sessionsOfPair($psychologist->id, $clientId))->exists();
    }

    /** 404 for anyone who is not this client's psychologist: the card must not even be confirmed to exist. */
    public function ensureClientOf(Psychologist $psychologist, string $clientId): void
    {
        abort_unless($this->isClientOf($psychologist, $clientId), 404, 'Клиент не найден.');
    }

    /** Diary access window, or null when the psychologist has no access at all. */
    public function diaryAccess(Psychologist $psychologist, string $clientId): ?AccessWindow
    {
        $eligible = $this->sessionsOfPair($psychologist->id, $clientId)->whereIn('status', self::DIARY_STATUSES)->exists();
        if (! $eligible) {
            return null;
        }

        return new AccessWindow($this->accessUntil($psychologist->id, $clientId));
    }

    /** End of the access window (DM-08) or null when there is no restriction. */
    public function accessUntil(string $psychologistId, string $clientId): ?CarbonImmutable
    {
        $until = ClientCard::where('psychologist_id', $psychologistId)->where('client_id', $clientId)->value('access_until');
        if ($until === null) {
            return null;
        }
        $until = CarbonImmutable::parse($until);

        return $this->resumedAfter($psychologistId, $clientId, $until) ? null : $until;
    }

    /** The client booked this psychologist again after the moment (a reschedule is not a new booking). */
    public function resumedAfter(string $psychologistId, string $clientId, CarbonInterface $moment): bool
    {
        return $this->sessionsOfPair($psychologistId, $clientId)
            ->whereNull('rescheduled_from_id')
            ->whereIn('status', self::DIARY_STATUSES)
            ->where('created_at', '>', $moment)
            ->exists();
    }

    /** Close the pair's access window: «работа завершена» or change of psychologist. */
    public function close(string $psychologistId, string $clientId, string $reason, CarbonInterface $at): ClientCard
    {
        return DB::transaction(function () use ($psychologistId, $clientId, $reason, $at) {
            $card = ClientCard::for($psychologistId, $clientId);
            $card = ClientCard::whereKey($card->id)->lockForUpdate()->first();
            $resumed = $card->access_until !== null && $this->resumedAfter($psychologistId, $clientId, $card->access_until);

            if ($card->access_until === null || $resumed) {
                $card->work_finished_at = null;
                $card->changed_psychologist_at = null;
                $card->access_until = $at;
            } elseif ($at->lessThan($card->access_until)) {
                $card->access_until = $at;
            }
            $field = $reason === 'changed' ? 'changed_psychologist_at' : 'work_finished_at';
            $card->{$field} ??= $at;
            $card->save();

            return $card;
        });
    }
}
