<?php

namespace App\Modules\Payments\Http;

use App\Modules\Booking\Models\BookingIntent;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\GiftCertificate;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\ReceiptService;

/** API representation of payments for CL-07 and ADM-07. */
class PaymentsPresenter
{
    public const STATUS_LABELS = [
        'created' => 'Создан',
        'requires_3ds' => 'Ожидает подтверждения',
        'unknown' => 'Статус уточняется',
        'succeeded' => 'Проведён',
        'declined' => 'Отклонён',
        'partially_refunded' => 'Возвращён частично',
        'refunded' => 'Возвращён',
    ];

    public const PURPOSE_LABELS = [
        'session' => 'Оплата сессии',
        'booking' => 'Оплата сессии',
        'certificate' => 'Подарочный сертификат',
        'supervision' => 'Супервизия',
        'event' => 'Мероприятие',
        'b2b_invoice' => 'Счёт компании',
    ];

    public static function session(Payment $p): ?TherapySession
    {
        if ($p->payable_type === (new TherapySession)->getMorphClass()) {
            return TherapySession::with('psychologist')->find($p->payable_id);
        }
        if ($p->payable_type === (new BookingIntent)->getMorphClass()) {
            $sessionId = BookingIntent::whereKey($p->payable_id)->value('therapy_session_id');

            return $sessionId ? TherapySession::with('psychologist')->find($sessionId) : null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function payment(Payment $p, bool $admin = false): array
    {
        $session = self::session($p);
        $out = [
            'id' => $p->id,
            'purpose' => $p->purpose,
            'purpose_label' => self::PURPOSE_LABELS[$p->purpose] ?? $p->purpose,
            'amount' => (int) $p->amount,
            'refunded_amount' => (int) $p->refunded_amount,
            'status' => $p->status,
            'status_label' => self::STATUS_LABELS[$p->status] ?? $p->status,
            'card_mask' => $p->card_mask,
            'with_payer' => (bool) $p->with_payer,
            'description' => $p->description,
            'error_message' => $p->error_message,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'session' => $session ? [
                'id' => $session->id,
                'starts_at' => $session->starts_at?->toIso8601String(),
                'psychologist' => $session->psychologist?->fullName(),
                'price' => (int) $session->price,
                'discount' => (int) $session->discount,
                'paid_balance' => (int) $session->paid_balance,
            ] : null,
            'receipts' => $p->receipts()->orderBy('created_at')->get()->map(fn ($r) => ReceiptService::toApi($r))->values(),
            'refunds' => $p->refunds()->orderBy('created_at')->get()->map(fn (PaymentRefund $r) => [
                'id' => $r->id,
                'amount' => (int) $r->amount,
                'status' => $r->status,
                'reason' => $r->reason,
                'error_code' => $r->error_code,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ];
        if ($admin) {
            $out['user'] = $p->user?->only(['id', 'name', 'last_name', 'email']);
            $out['gateway'] = $p->gateway;
            $out['gateway_payment_id'] = $p->gateway_payment_id;
            $out['idempotency_key'] = $p->idempotency_key;
            $out['error_code'] = $p->error_code;
            $out['error_category'] = $p->error_category;
        }

        return $out;
    }

    /** Minimal public status for /pay/return (no personal data). */
    public static function publicStatus(Payment $p): array
    {
        $result = null;
        if ($p->purpose === 'booking') {
            $intent = BookingIntent::find($p->meta('booking_intent_id'));
            $result = $intent ? ['intent_status' => $intent->status, 'session_id' => $intent->therapy_session_id, 'failure_reason' => $intent->failure_reason] : null;
        } elseif ($p->purpose === 'certificate') {
            $c = GiftCertificate::find($p->meta('certificate_id'));
            $result = $c ? ['certificate_status' => $c->status, 'send_to' => $c->send_to] : null;
        } elseif ($p->purpose === 'session') {
            $result = ['charge_task_id' => $p->meta('charge_task_id')];
        }

        return [
            'id' => $p->id,
            'status' => $p->status,
            'status_label' => self::STATUS_LABELS[$p->status] ?? $p->status,
            'purpose' => $p->purpose,
            'purpose_label' => self::PURPOSE_LABELS[$p->purpose] ?? $p->purpose,
            'amount' => (int) $p->amount,
            'description' => $p->description,
            'error_message' => $p->error_message,
            'confirmation_url' => $p->status === 'requires_3ds' ? $p->confirmation_url : null,
            'next' => $p->meta('next'),
            'result' => $result,
        ];
    }
}
