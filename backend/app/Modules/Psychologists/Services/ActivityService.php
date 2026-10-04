<?php

namespace App\Modules\Psychologists\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Notifier;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\SupervisionMonthRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ST-09 and DEC-21 / DEC-37: one paid supervision per calendar month (Moscow time) is required to withdraw money
 * and to stay visible. The requirement starts with the first full calendar month after qualification approval.
 * Supervisors who do not take clients are exempt [assumption Q-53]. Scheduled sessions of an inactive
 * psychologist are held and paid; new bookings are closed until activation.
 *
 * SUPERV calls markMonthMet() when a supervision is held and paid (or credited by an admin);
 * PAYOUT calls payoutAllowed() before adding a payee to the weekly registry.
 */
class ActivityService
{
    public function __construct(private Notifier $notifier) {}

    public static function month(?CarbonImmutable $at = null): CarbonImmutable
    {
        return ($at ?? CarbonImmutable::now())->setTimezone(config('platform.timezone'))->startOfMonth();
    }

    /** Called when qualification is approved (ST-08 → ST-09 "Активен, льготный период"). */
    public function onQualified(Psychologist $p, ?string $actorId = null): void
    {
        if ($p->activity_status === null) {
            $p->transitionTo('grace', $actorId, 'qualification approved', field: 'activity_status', event: 'psy.activity.grace');
        }
    }

    /** Does the requirement apply to this psychologist in the given month? */
    public function applies(Psychologist $p, CarbonImmutable $month): bool
    {
        if ($p->qualified_at === null) {
            return false;
        }
        $approvalMonth = self::month(CarbonImmutable::parse($p->qualified_at));
        if ($month <= $approvalMonth) {
            return false;
        }
        if ($p->user?->hasRole('supervisor') && ! $this->leadsClients($p)) {
            return false;
        }

        return true;
    }

    public function isMet(Psychologist $p, CarbonImmutable $month): bool
    {
        return SupervisionMonthRequirement::where('psychologist_id', $p->id)
            ->where('month', $month->toDateString())
            ->exists();
    }

    /** Requirement status for PRO-09, PRO-12, ADM-15. */
    public function currentStatus(Psychologist $p): array
    {
        $month = self::month();
        $applies = $this->applies($p, $month);

        return [
            'month' => $month->toDateString(),
            'applies' => $applies,
            'met' => ! $applies || $this->isMet($p, $month),
            'activity_status' => $p->activity_status,
            'deadline' => $month->endOfMonth()->toIso8601String(),
        ];
    }

    /** A supervision was held and paid (or credited by an admin with a reason). */
    public function markMonthMet(Psychologist $p, ?Model $source = null, ?string $actorId = null, ?string $reason = null, ?CarbonImmutable $at = null): SupervisionMonthRequirement
    {
        $month = self::month($at);

        return DB::transaction(function () use ($p, $month, $source, $actorId, $reason) {
            $record = SupervisionMonthRequirement::firstOrNew(['psychologist_id' => $p->id, 'month' => $month->toDateString()]);
            $record->fill([
                'status' => 'met',
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'credited_by' => $actorId,
                'reason' => $reason,
                'met_at' => $record->met_at ?? now(),
            ])->save();

            if ($month->equalTo(self::month()) && in_array($p->activity_status, ['active_not_met', 'inactive', 'grace'], true)) {
                $wasInactive = $p->activity_status === 'inactive';
                $p->transitionTo('active_met', $actorId, $reason ?? 'supervision held', field: 'activity_status', event: 'psy.activity.changed', context: ['activity_status' => 'active_met']);
                if ($wasInactive) {
                    $this->notifier->send($p->user, 'psy.activity_restored', [], '/pro');
                }
            }
            if ($actorId && $reason) {
                Audit::log('ADM-15', 'supervision.month_credited', $p, ['month' => $month->toDateString()], $reason, $actorId);
            }

            return $record;
        });
    }

    /** Weekly payout is allowed only if the current month requirement is met or does not apply (DEC-37, p. 8). */
    public function payoutAllowed(User $user): bool
    {
        $p = $user->psychologist;
        if (! $p || $p->qualified_at === null) {
            return true;
        }
        $month = self::month();

        return ! $this->applies($p, $month) || $this->isMet($p, $month);
    }

    /**
     * Run on the 1st of each month at 00:30 MSK: evaluate the previous month and reset the current one.
     *
     * @return array{inactivated: int, reset: int}
     */
    public function monthlyRollover(?CarbonImmutable $now = null): array
    {
        $current = self::month($now);
        $previous = $current->subMonth();
        $stats = ['inactivated' => 0, 'reset' => 0];

        Psychologist::with('user.roles')->where('qualification_status', 'approved')->whereNotNull('activity_status')
            ->each(function (Psychologist $p) use ($current, $previous, &$stats) {
                $prevFailed = $this->applies($p, $previous) && ! $this->isMet($p, $previous);
                $currentApplies = $this->applies($p, $current);
                $currentMet = ! $currentApplies || $this->isMet($p, $current);

                if ($prevFailed && $p->activity_status !== 'inactive' && ! $currentMet) {
                    $p->transitionTo('inactive', reason: 'no supervision in '.$previous->format('Y-m'), field: 'activity_status', event: 'psy.activity.changed', context: ['activity_status' => 'inactive']);
                    $this->notifier->send($p->user, 'psy.activity_inactive', ['month' => $previous->locale('ru')->translatedFormat('F Y')], '/pro/supervision');
                    $stats['inactivated']++;

                    return;
                }
                if ($p->activity_status === 'inactive') {
                    if ($currentMet) {
                        $p->transitionTo('active_met', reason: 'requirement met', field: 'activity_status', event: 'psy.activity.changed', context: ['activity_status' => 'active_met']);
                    }

                    return;
                }
                $target = ! $currentApplies && $p->activity_status === 'grace' ? 'grace' : ($currentMet ? 'active_met' : 'active_not_met');
                if ($target !== $p->activity_status && $p->canTransition($target, 'activity_status')) {
                    $p->transitionTo($target, reason: 'new month', field: 'activity_status', event: 'psy.activity.changed', context: ['activity_status' => $target]);
                    $stats['reset']++;
                }
            });

        return $stats;
    }

    /** A supervisor "leads clients" when they had or have therapy sessions in the last 90 days or ahead. */
    private function leadsClients(Psychologist $p): bool
    {
        return TherapySession::where('psychologist_id', $p->id)
            ->where('starts_at', '>=', now()->subDays(90))
            ->whereNotIn('status', [TherapySession::CANCELLED_BY_CLIENT, TherapySession::CANCELLED_BY_PSY, TherapySession::CANCELLED_BY_SYSTEM])
            ->exists();
    }
}
