<?php

namespace App\Modules\Booking\Services;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Support\SessionTime;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Support\AdminRecipients;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Money;
use App\Support\Settings\Settings;
use DateTimeInterface;

/**
 * Letters and notification-centre entries of BOOK. Each recipient sees the time in their own timezone (DEC-16).
 * Letters never carry the client's requests or other "сведения о состоянии" (BR-NOTIF-04).
 */
class BookingNotifications
{
    public function __construct(private Notifier $notifier) {}

    public function booked(TherapySession $s, string $paymentNote): void
    {
        $client = $this->client($s);
        if ($client) {
            $this->notifier->send($client, 'book.session_booked', [
                ...$this->clientVars($s),
                'payment_note' => $paymentNote,
                'room_open' => Settings::int('P-ROOM-OPEN'),
            ], '/client/sessions', 'Открыть кабинет');
        }
        $this->toPsychologist($s, 'book.psy_new_booking');
    }

    public function rescheduled(TherapySession $s, DateTimeInterface $oldStart, string $paymentNote): void
    {
        $client = $this->client($s);
        if ($client) {
            $this->notifier->send($client, 'book.session_rescheduled', [
                ...$this->clientVars($s),
                'old_date' => SessionTime::format($oldStart, $s->client_timezone),
                'payment_note' => $paymentNote,
            ], '/client/sessions');
        }
        $this->toPsychologist($s, 'book.psy_session_rescheduled', [
            'old_date' => SessionTime::format($oldStart, $this->psyTz($s)),
        ]);
    }

    public function cancelledByClient(TherapySession $s, string $refundNote, bool $notifyClient = true, bool $notifyPsychologist = true): void
    {
        $client = $this->client($s);
        if ($client && $notifyClient) {
            $this->notifier->send($client, 'book.session_cancelled_client', [...$this->clientVars($s), 'refund_note' => $refundNote], '/client/sessions');
        }
        if ($notifyPsychologist) {
            $this->toPsychologist($s, 'book.psy_session_cancelled');
        }
    }

    public function cancelledByPsychologist(TherapySession $s, bool $charged): void
    {
        $client = $this->client($s);
        if (! $client) {
            return;
        }
        if ($charged) {
            $this->notifier->send($client, 'book.psy_cancelled_choice', [
                ...$this->clientVars($s),
                'amount' => $s->isCorporate() ? 'лимит сессии' : Money::format($s->retainedAmount()),
                'deadline' => SessionTime::format($s->choice_deadline_at, $s->client_timezone),
            ], '/client/sessions?choice='.$s->id, 'Выбрать');
        } else {
            $slug = $s->psychologist?->slug;
            $this->notifier->send($client, 'book.psy_cancelled_free', $this->clientVars($s), $slug ? '/psychologists/'.$slug : '/client/sessions');
        }
    }

    public function cancelledByPlatform(TherapySession $s, string $refundNote): void
    {
        $client = $this->client($s);
        if ($client) {
            $this->notifier->send($client, 'book.session_cancelled_platform', [...$this->clientVars($s), 'refund_note' => $refundNote], '/client/sessions');
        }
        $psychologist = $s->psychologist?->user;
        if ($psychologist && $psychologist->isActive()) {
            $this->toPsychologist($s, 'book.psy_session_cancelled');
        }
    }

    public function cancelledUnpaid(TherapySession $s): void
    {
        $client = $this->client($s);
        if ($client) {
            $this->notifier->send($client, 'book.session_cancelled_unpaid', $this->clientVars($s), '/client/payments');
        }
        $this->toPsychologist($s, 'book.psy_session_cancelled');
    }

    public function choiceNeeded(TherapySession $s): void
    {
        $client = $this->client($s);
        if (! $client) {
            return;
        }
        $code = match ($s->status) {
            TherapySession::PSY_NO_SHOW => 'book.psy_no_show_choice',
            TherapySession::TECH_ISSUE => 'book.tech_issue_choice',
            default => 'book.psy_cancelled_choice',
        };
        $this->notifier->send($client, $code, [
            ...$this->clientVars($s),
            'amount' => $s->isCorporate() ? 'лимит сессии' : Money::format($s->retainedAmount()),
            'deadline' => SessionTime::format($s->choice_deadline_at, $s->client_timezone),
        ], '/client/sessions?choice='.$s->id, 'Выбрать');
    }

    public function clientNoShow(TherapySession $s): void
    {
        $client = $this->client($s);
        if ($client) {
            $this->notifier->send($client, 'book.client_no_show', $this->clientVars($s), '/client/payments');
        }
    }

    public function psyNoShow(TherapySession $s): void
    {
        $this->toPsychologist($s, 'book.psy_no_show_recorded');
        foreach (AdminRecipients::withPermission('admin.sessions.view') as $admin) {
            $this->notifier->send($admin, 'book.psy_no_show_admin', [
                'psychologist' => $s->psychologist?->fullName(),
                'date' => SessionTime::format($s->starts_at, $admin->timezone),
                'session_id' => $s->id,
            ], '/admin/sessions/'.$s->id);
        }
    }

    public function refundCredited(TherapySession $s, int $amount): void
    {
        $client = $this->client($s);
        if ($client && $amount > 0) {
            $this->notifier->send($client, 'book.refund_credited', [...$this->clientVars($s), 'amount' => Money::format($amount)], '/client/payments');
        }
    }

    public function changePsychologist(User $client, Psychologist $p, int $count, int $amount): void
    {
        $this->notifier->send($client, 'book.change_psychologist_done', [
            'psychologist' => $p->fullName(),
            'count' => $count,
            'amount' => Money::format($amount),
        ], '/client/payments');
    }

    public function reminder(TherapySession $s, int $minutes): void
    {
        $when = match (true) {
            $minutes >= 90 => 'через '.(int) round($minutes / 60).' ч',
            $minutes >= 50 => 'через час',
            default => 'через '.$minutes.' мин',
        };
        $vars = ['when' => $when, 'room_open' => Settings::int('P-ROOM-OPEN')];
        foreach (array_filter([$this->client($s), $s->partner_user_id ? User::find($s->partner_user_id) : null]) as $user) {
            $this->notifier->send($user, 'book.session_reminder', [...$this->clientVars($s, $user->timezone ?: $s->client_timezone), ...$vars], '/client/sessions');
        }
        $this->toPsychologist($s, 'book.psy_session_reminder', $vars);
    }

    public function pairInvitation(TherapySession $s): void
    {
        if (! $s->partner_email) {
            return;
        }
        $client = $this->client($s);
        $vars = [
            'inviter' => $client?->name ?? 'Клиент ТЕТА',
            'psychologist' => $s->psychologist?->fullName(),
            'date' => SessionTime::format($s->starts_at, $s->client_timezone),
        ];
        $link = '/client/sessions?invite='.$s->id;
        $existing = User::where('email', mb_strtolower($s->partner_email))->first();
        if ($existing) {
            $this->notifier->send($existing, 'book.pair_invitation', $vars, $link, 'Принять приглашение');
        } else {
            $this->notifier->sendToEmail($s->partner_email, 'book.pair_invitation', $vars, $link, 'Принять приглашение');
        }
    }

    public function pairAccepted(TherapySession $s): void
    {
        $client = $this->client($s);
        if ($client) {
            $this->notifier->send($client, 'book.pair_accepted', $this->clientVars($s), '/client/sessions');
        }
    }

    public function qualityThreshold(Psychologist $p, int $count): void
    {
        foreach (AdminRecipients::withPermission('admin.psychologists.view') as $admin) {
            $this->notifier->send($admin, 'book.quality_threshold', ['psychologist' => $p->fullName(), 'count' => $count], '/admin/sessions?psychologist_id='.$p->id);
        }
    }

    /** @return array<string, mixed> */
    public function clientVars(TherapySession $s, ?string $tz = null): array
    {
        return [
            'date' => SessionTime::format($s->starts_at, $tz ?? $s->client_timezone),
            'psychologist' => $s->psychologist?->fullName(),
            'format' => SessionTime::formatLabel($s->format),
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function toPsychologist(TherapySession $s, string $code, array $extra = []): void
    {
        $user = $s->psychologist?->user;
        if (! $user) {
            return;
        }
        $this->notifier->send($user, $code, [
            'date' => SessionTime::format($s->starts_at, $this->psyTz($s)),
            'client' => $this->client($s)?->name ?? 'Клиент',
            'format' => SessionTime::formatLabel($s->format),
            ...$extra,
        ], '/pro');
    }

    private function psyTz(TherapySession $s): string
    {
        return $s->psychologist?->timezone ?: ($s->psychologist?->user?->timezone ?: (string) config('platform.timezone'));
    }

    private function client(TherapySession $s): ?User
    {
        return User::withTrashed()->find($s->client_id);
    }
}
