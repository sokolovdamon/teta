<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\SessionPayments;
use App\Modules\Booking\Support\BookingError;
use App\Modules\Booking\Support\SessionTime;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Support\AdminRecipients;
use App\Support\Calendar\WorkingDays;
use App\Support\Money;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ST-05 / SEQ-06 / DEC-23: complaint about a charge. Accepted only for money actually retained for a session;
 * decided within P-COMPLAINT-REVIEW working days of the production calendar. An approved full or partial refund
 * is credited to the cabinet balance (Q-52) and pay.complaint.refunded {session_id, refund_amount, share_percent}
 * lets PAYOUT reverse the psychologist's accrual by the same share (Q-56).
 */
class ComplaintService
{
    public const OPEN = ['submitted', 'in_review', 'waiting_client'];

    public function __construct(
        private SessionPayments $sessionPayments,
        private CorporateCoverage $coverage,
        private Notifier $notifier,
    ) {}

    public static function dueDate(?CarbonImmutable $from = null): CarbonImmutable
    {
        $today = ($from ?? CarbonImmutable::now())->setTimezone(config('platform.timezone'))->startOfDay();

        return WorkingDays::add($today, Settings::int('P-COMPLAINT-REVIEW'));
    }

    public function create(User $client, TherapySession $session, string $reason): ChargeComplaint
    {
        abort_unless($session->client_id === $client->id, 404);
        if (in_array($session->status, [TherapySession::PSY_NO_SHOW, TherapySession::TECH_ISSUE, TherapySession::CANCELLED_BY_PSY], true)) {
            BookingError::fail('По этой сессии доступен полный возврат или бесплатный перенос — выберите его в разделе «Сессии».', 'use_choice', 'session_id');
        }
        $charged = $session->isCorporate() ? $session->paid_at !== null : $session->retainedAmount() > 0;
        if (! $charged || in_array($session->status, [TherapySession::BOOKED, TherapySession::CANCELLED_BY_SYSTEM], true)) {
            BookingError::fail('Жалоба принимается только по списанной оплате. Отмена до списания бесплатна.', 'not_charged', 'session_id');
        }

        $existing = ChargeComplaint::where('therapy_session_id', $session->id)->whereIn('status', self::OPEN)->first();
        if ($existing) {
            // A new message is added to the open complaint [Рек.].
            $existing->forceFill(['messages' => [...($existing->messages ?? []), ['from' => 'client', 'text' => $reason, 'at' => now()->toIso8601String()]]])->save();

            return $existing;
        }

        $complaint = DB::transaction(function () use ($client, $session, $reason) {
            $c = new ChargeComplaint;
            $c->forceFill([
                'therapy_session_id' => $session->id,
                'payment_id' => $session->payment_id,
                'client_id' => $client->id,
                'reason' => $reason,
                'status' => 'submitted',
                'due_date' => self::dueDate()->toDateString(),
                'amount_charged' => $session->isCorporate() ? 0 : $session->retainedAmount(),
                'messages' => [['from' => 'client', 'text' => $reason, 'at' => now()->toIso8601String()]],
            ])->save();
            $c->recordInitialState($client->id, ['session_id' => $session->id, 'due_date' => $c->due_date->toDateString()], event: 'pay.complaint.opened');

            return $c;
        });

        $this->notifier->send($client, 'pay.complaint_received', [
            'date' => SessionTime::format($session->starts_at, $session->client_timezone),
            'due_date' => SessionTime::date($complaint->due_date, $client->timezone),
        ], '/client/payments#complaints');
        foreach (AdminRecipients::withPermission('admin.finance.complaints') as $admin) {
            $this->notifier->send($admin, 'pay.complaint_new_admin', ['due_date' => SessionTime::date($complaint->due_date, $admin->timezone)], '/admin/finance?tab=complaints');
        }

        return $complaint;
    }

    public function take(ChargeComplaint $c, User $admin): ChargeComplaint
    {
        $c->transitionTo('in_review', $admin->id, 'taken', ['assigned_to' => $admin->id, 'taken_at' => now()]);
        Audit::log('ADM-07', 'complaint.taken', $c, null, null, $admin->id);

        return $c;
    }

    public function ask(ChargeComplaint $c, User $admin, string $question): ChargeComplaint
    {
        $c->transitionTo('waiting_client', $admin->id, 'question', [
            'messages' => [...($c->messages ?? []), ['from' => 'admin', 'text' => $question, 'at' => now()->toIso8601String()]],
        ]);
        Audit::log('ADM-07', 'complaint.question', $c, null, $question, $admin->id);
        if ($client = User::find($c->client_id)) {
            $this->notifier->send($client, 'pay.complaint_question', ['question' => $question], '/client/payments#complaints');
        }

        return $c;
    }

    public function answer(ChargeComplaint $c, User $client, string $text): ChargeComplaint
    {
        abort_unless($c->client_id === $client->id, 404);
        if ($c->status !== 'waiting_client') {
            BookingError::fail('Администратор сейчас не ждёт ответа по этой жалобе.', 'invalid_status', 'text', 409);
        }
        $c->transitionTo('in_review', $client->id, 'client answered', [
            'messages' => [...($c->messages ?? []), ['from' => 'client', 'text' => $text, 'at' => now()->toIso8601String()]],
        ]);

        return $c;
    }

    public function resume(ChargeComplaint $c, User $admin): ChargeComplaint
    {
        $c->transitionTo('in_review', $admin->id, 'resumed without answer');
        Audit::log('ADM-07', 'complaint.resumed', $c, null, null, $admin->id);

        return $c;
    }

    public function reject(ChargeComplaint $c, User $admin, string $comment): ChargeComplaint
    {
        $c->transitionTo('rejected', $admin->id, $comment, ['decision_comment' => $comment, 'decided_by' => $admin->id, 'decided_at' => now(), 'sla_level' => null]);
        Audit::log('ADM-07', 'complaint.rejected', $c, null, $comment, $admin->id);
        if ($client = User::find($c->client_id)) {
            $this->notifier->send($client, 'pay.complaint_rejected', ['comment' => $comment], '/client/payments#complaints');
        }

        return $c;
    }

    /** Full or partial refund: approved → credit to the balance (corporate: limit restored) → refunded. */
    public function approve(ChargeComplaint $c, User $admin, int $amount, string $comment): ChargeComplaint
    {
        $c = DB::transaction(function () use ($c, $admin, $amount, $comment) {
            $c = ChargeComplaint::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $session = TherapySession::whereKey($c->therapy_session_id)->lockForUpdate()->firstOrFail();
            $corporate = $session->isCorporate();
            $max = $corporate ? 0 : $session->retainedAmount();
            if (! $corporate && ($amount <= 0 || $amount > $max)) {
                throw ValidationException::withMessages(['amount' => 'Сумма возврата — от 1 копейки до '.Money::format($max).'.']);
            }
            $refund = $corporate ? 0 : $amount;
            $base = max(1, (int) $session->amount_charged);
            $share = $corporate ? 100.0 : round($refund * 100 / $base, 4);
            $c->transitionTo('approved', $admin->id, $comment, [
                'refund_amount' => $refund,
                'share_percent' => $share,
                'decision_comment' => $comment,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'sla_level' => null,
            ], ['session_id' => $session->id, 'refund_amount' => $refund]);

            if ($corporate) {
                $this->coverage->release($session);
            } else {
                $this->sessionPayments->creditToBalance($session, $refund, 'complaint', $admin->id, $comment, $c);
            }
            $c->transitionTo('refunded', $admin->id, 'credited to balance', context: [
                'session_id' => $session->id,
                'refund_amount' => $refund,
                'share_percent' => $share,
                'amount_charged' => (int) $session->amount_charged,
                'psychologist_id' => $session->psychologist_id,
                'corporate' => $corporate,
            ]);
            Audit::log('ADM-07', 'complaint.approved', $c, ['refund_amount' => $refund, 'share_percent' => $share], $comment, $admin->id);

            return $c;
        });
        if ($client = User::find($c->client_id)) {
            $this->notifier->send($client, 'pay.complaint_refunded', [
                'amount' => $c->refund_amount > 0 ? Money::format((int) $c->refund_amount) : 'лимит корпоративной программы',
                'comment' => $comment,
            ], '/client/payments#complaints');
        }

        return $c;
    }

    public function withdraw(ChargeComplaint $c, User $client): ChargeComplaint
    {
        abort_unless($c->client_id === $client->id, 404);
        if (! $c->canTransition('withdrawn')) {
            BookingError::fail('Эту жалобу уже нельзя отозвать.', 'invalid_status', 'status', 409);
        }
        $c->transitionTo('withdrawn', $client->id, 'withdrawn by client', ['withdrawn_at' => now(), 'sla_level' => null]);
        $this->notifier->send($client, 'pay.complaint_withdrawn', [], '/client/payments#complaints');

        return $c;
    }

    /**
     * Daily SLA control (BR-CANC-05): less than 3 working days left → "soon" (highlighted in ADM-07),
     * past the due date → "overdue" with an alert to super admins.
     *
     * @return array{soon: int, overdue: int}
     */
    public function checkSla(): array
    {
        $stats = ['soon' => 0, 'overdue' => 0];
        $today = CarbonImmutable::now()->setTimezone(config('platform.timezone'))->startOfDay();
        foreach (ChargeComplaint::whereIn('status', self::OPEN)->get() as $c) {
            $level = self::slaLevel($c, $today);
            if ($level === null) {
                continue;
            }
            $stats[$level]++;
            if ($c->sla_level === $level) {
                continue;
            }
            $c->forceFill(['sla_level' => $level, 'sla_alerted_at' => now()])->save();
            $recipients = $level === 'overdue' ? AdminRecipients::superAdmins() : AdminRecipients::withPermission('admin.finance.complaints');
            foreach ($recipients as $admin) {
                $this->notifier->send($admin, $level === 'overdue' ? 'pay.complaint_overdue_admin' : 'pay.complaint_due_soon_admin', [
                    'due_date' => SessionTime::date($c->due_date, $admin->timezone),
                ], '/admin/finance?tab=complaints');
            }
        }

        return $stats;
    }

    public static function slaLevel(ChargeComplaint $c, ?CarbonImmutable $today = null): ?string
    {
        if (! in_array($c->status, self::OPEN, true)) {
            return null;
        }
        $today ??= CarbonImmutable::now()->setTimezone(config('platform.timezone'))->startOfDay();
        $due = CarbonImmutable::parse($c->due_date->toDateString(), config('platform.timezone'))->startOfDay();
        if ($due < $today) {
            return 'overdue';
        }

        return WorkingDays::between($today, $due) < 3 ? 'soon' : null;
    }

    public static function toApi(ChargeComplaint $c, bool $admin = false): array
    {
        $session = $c->relationLoaded('session') ? $c->session : TherapySession::find($c->therapy_session_id);
        $today = CarbonImmutable::now()->setTimezone(config('platform.timezone'))->startOfDay();
        $due = CarbonImmutable::parse($c->due_date->toDateString(), config('platform.timezone'));

        return [
            'id' => $c->id,
            'status' => $c->status,
            'reason' => $c->reason,
            'messages' => $c->messages ?? [],
            'due_date' => $c->due_date->toDateString(),
            'working_days_left' => $due >= $today ? WorkingDays::between($today, $due) : -WorkingDays::between($due, $today),
            'sla' => self::slaLevel($c, $today),
            'amount_charged' => (int) $c->amount_charged,
            'refund_amount' => $c->refund_amount,
            'share_percent' => $c->share_percent,
            'decision_comment' => $c->decision_comment,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'can_withdraw' => in_array($c->status, ['submitted', 'in_review'], true),
            'session' => $session ? [
                'id' => $session->id,
                'starts_at' => $session->starts_at?->toIso8601String(),
                'status' => $session->status,
                'psychologist' => $session->psychologist?->fullName(),
            ] : null,
            'client' => $admin ? User::withTrashed()->find($c->client_id)?->only(['id', 'name', 'last_name', 'email']) : null,
            'assigned_to' => $admin ? User::find($c->assigned_to)?->only(['id', 'name', 'last_name']) : null,
        ];
    }
}
