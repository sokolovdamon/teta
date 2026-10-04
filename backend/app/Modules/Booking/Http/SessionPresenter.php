<?php

namespace App\Modules\Booking\Http;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Models\ChargeTask;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;

/**
 * API representation of a session for each audience. The client's requests ("сведения о состоянии") are shown
 * only to the session's psychologist — never to the client's partner, admins or other specialists.
 */
class SessionPresenter
{
    public const STATUS_LABELS = [
        TherapySession::BOOKED => 'Забронирована',
        TherapySession::PAID => 'Оплачена',
        TherapySession::IN_PROGRESS => 'Идёт',
        TherapySession::HELD => 'Проведена',
        TherapySession::CLIENT_NO_SHOW => 'Неявка клиента',
        TherapySession::PSY_NO_SHOW => 'Неявка психолога',
        TherapySession::TECH_ISSUE => 'Техническая проблема',
        TherapySession::CANCELLED_BY_CLIENT => 'Отменена клиентом',
        TherapySession::CANCELLED_BY_PSY => 'Отменена психологом',
        TherapySession::CANCELLED_BY_SYSTEM => 'Отменена системой',
    ];

    public function __construct(private BookingService $booking) {}

    /** @return array<string, mixed> */
    public function base(TherapySession $s): array
    {
        $start = CarbonImmutable::parse($s->starts_at);
        $end = CarbonImmutable::parse($s->ends_at);
        $opens = $start->subMinutes(Settings::int('P-ROOM-OPEN'));
        $closes = $end->addMinutes(Settings::int('P-ROOM-CLOSE'));
        $psy = $s->psychologist;

        return [
            'id' => $s->id,
            'status' => $s->status,
            'status_label' => self::STATUS_LABELS[$s->status] ?? $s->status,
            'format' => $s->format,
            'format_label' => $s->format === 'pair' ? 'Парная сессия' : 'Индивидуальная сессия',
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'duration_min' => (int) $s->duration_min,
            'psychologist' => $psy ? [
                'id' => $psy->id,
                'slug' => $psy->slug,
                'name' => $psy->fullName(),
                'timezone' => $psy->timezone,
            ] : null,
            'price' => (int) $s->price,
            'discount' => (int) $s->discount,
            'amount_due' => (int) $s->amount_due,
            'amount_charged' => (int) $s->amount_charged,
            'balance_refunded' => (int) $s->balance_refunded,
            'payment_source' => $s->payment_source,
            'payment_status' => $this->paymentStatus($s),
            'is_corporate' => $s->isCorporate(),
            'paid_at' => $s->paid_at?->toIso8601String(),
            'client_choice' => $s->client_choice,
            'choice_deadline_at' => $s->choice_deadline_at?->toIso8601String(),
            'cancel_kind' => $s->cancel_kind,
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
            'rescheduled_from_id' => $s->rescheduled_from_id,
            'reschedule_count' => (int) $s->reschedule_count,
            'outcome_source' => $s->outcome_source,
            'room' => [
                'url' => '/room/session/'.$s->id,
                'opens_at' => $opens->toIso8601String(),
                'closes_at' => $closes->toIso8601String(),
                'available' => in_array($s->status, [TherapySession::PAID, TherapySession::IN_PROGRESS], true) && now()->between($opens, $closes),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function forClient(TherapySession $s, User $viewer): array
    {
        $isPartner = $viewer->id === $s->partner_user_id && $viewer->id !== $s->client_id;
        $data = $this->base($s);
        $data['role'] = $isPartner ? 'partner' : 'client';
        $data['timezone'] = $isPartner ? ($viewer->timezone ?: $s->client_timezone) : $s->client_timezone;
        if ($isPartner) {
            unset($data['price'], $data['discount'], $data['amount_due'], $data['amount_charged'], $data['balance_refunded'], $data['payment_source'], $data['paid_at']);
            $data['actions'] = ['join' => $data['room']['available']];

            return $data;
        }

        $upcoming = in_array($s->status, [TherapySession::BOOKED, TherapySession::PAID], true) && CarbonImmutable::parse($s->starts_at) > now();
        $charged = $this->booking->isCharged($s);
        /** @var ChargeTask|null $task */
        $task = $s->relationLoaded('chargeTask') ? $s->chargeTask : $s->chargeTask()->first();
        $lateLimit = (int) $s->param('P-LATE-RESCHEDULE-LIMIT');
        $complaint = ChargeComplaint::where('therapy_session_id', $s->id)->latest()->first();

        $data['charge'] = $task ? [
            'id' => $task->id,
            'status' => $task->status,
            'due_at' => $task->due_at?->toIso8601String(),
            'deadline_at' => $task->deadline_at?->toIso8601String(),
            'last_error_category' => $task->last_error_category,
            'last_error_code' => $task->last_error_code,
        ] : null;
        $data['is_charged'] = $charged;
        $data['free_cancel_until'] = ! $charged && $upcoming ? CarbonImmutable::parse($s->charge_due_at ?? $s->starts_at)->toIso8601String() : null;
        $data['late_reschedules_left'] = $charged ? max(0, $lateLimit - (int) $s->late_reschedule_count) : null;
        $data['partner'] = $s->format === 'pair' ? [
            'email' => $s->partner_email,
            'accepted' => $s->partner_user_id !== null,
        ] : null;
        $data['complaint'] = $complaint ? ['id' => $complaint->id, 'status' => $complaint->status, 'due_date' => $complaint->due_date?->toDateString()] : null;
        $canComplain = ! $complaint || ! in_array($complaint->status, ['submitted', 'in_review', 'waiting_client'], true);
        $data['actions'] = [
            'join' => $data['room']['available'],
            'reschedule' => $upcoming && (! $charged || (int) $s->late_reschedule_count < $lateLimit) && (! $task || $task->status === 'scheduled' || $charged),
            'cancel' => $upcoming,
            'cancel_free' => $upcoming && ! $charged,
            'choose' => $s->client_choice === 'pending',
            'pay' => $task && $task->status === 'retry_wait',
            'complaint' => $canComplain && ! $s->isCorporate() && $s->retainedAmount() > 0
                && in_array($s->status, [TherapySession::PAID, TherapySession::IN_PROGRESS, TherapySession::HELD, TherapySession::CLIENT_NO_SHOW, TherapySession::CANCELLED_BY_CLIENT], true),
        ];

        return $data;
    }

    /** @return array<string, mixed> */
    public function forPsychologist(TherapySession $s): array
    {
        $data = $this->base($s);
        unset($data['discount'], $data['amount_due'], $data['balance_refunded'], $data['payment_source']);
        $client = $s->relationLoaded('client') ? $s->client : User::withTrashed()->find($s->client_id);
        $requestIds = $s->client_request_ids ?? [];
        $data['client'] = ['id' => $s->client_id, 'name' => $client?->name ?? 'Клиент'];
        $data['partner_joined'] = $s->partner_user_id !== null;
        $data['client_requests'] = $requestIds ? ClientRequest::whereIn('id', $requestIds)->orderBy('carousel_sort')->pluck('title')->values()->all() : [];
        $data['log'] = $this->log($s);
        $upcoming = in_array($s->status, [TherapySession::BOOKED, TherapySession::PAID], true) && CarbonImmutable::parse($s->starts_at) > now();
        $started = in_array($s->status, [TherapySession::PAID, TherapySession::IN_PROGRESS], true) && CarbonImmutable::parse($s->starts_at) <= now();
        $data['actions'] = [
            'join' => $data['room']['available'],
            'cancel' => $upcoming,
            'reschedule' => $upcoming,
            'outcome' => $started ? ['held', 'client_no_show', 'tech_issue'] : [],
        ];

        return $data;
    }

    /** @return array<string, mixed> */
    public function forAdmin(TherapySession $s): array
    {
        $data = $this->base($s);
        $client = $s->relationLoaded('client') ? $s->client : User::withTrashed()->find($s->client_id);
        $data['client'] = $client ? ['id' => $client->id, 'name' => $client->fullName(), 'email' => $client->email] : null;
        $data['partner'] = $s->partner_email ? ['email' => $s->partner_email, 'user_id' => $s->partner_user_id] : null;
        $data['client_timezone'] = $s->client_timezone;
        $data['source'] = $s->source;
        $data['cancel_reason'] = $s->cancel_reason;
        $data['charge_due_at'] = $s->charge_due_at?->toIso8601String();
        $data['charge_deadline_at'] = $s->charge_deadline_at?->toIso8601String();
        $data['paid'] = ['card' => (int) $s->paid_card, 'balance' => (int) $s->paid_balance, 'certificate' => (int) $s->paid_certificate];
        $data['log'] = $this->log($s);
        $data['late_reschedule_count'] = (int) $s->late_reschedule_count;

        return $data;
    }

    /** Session log fields written by TetaMeet (ROOM-05). */
    public function log(TherapySession $s): array
    {
        return [
            'client_joined_at' => $s->client_joined_at?->toIso8601String(),
            'psychologist_joined_at' => $s->psychologist_joined_at?->toIso8601String(),
            'joint_duration_sec' => $s->joint_duration_sec,
            'actual_duration_sec' => $s->actual_duration_sec,
        ];
    }

    public function paymentStatus(TherapySession $s): string
    {
        if ($s->isCorporate()) {
            return 'Оплачивает компания';
        }
        if ((int) $s->balance_refunded > 0 && (int) $s->balance_refunded >= (int) $s->amount_charged) {
            return $s->client_choice === 'reschedule' ? 'Оплата перенесена' : 'Возвращено на баланс';
        }
        if ($s->paid_at) {
            return $s->amount_charged > 0 ? 'Оплачена' : 'Бесплатно';
        }
        if (in_array($s->status, [TherapySession::BOOKED], true)) {
            return (int) $s->amount_due === 0 ? 'Бесплатно' : 'Ожидает списания';
        }

        return 'Не оплачивалась';
    }
}
