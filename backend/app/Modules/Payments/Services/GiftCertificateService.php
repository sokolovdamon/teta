<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Booking\Support\BookingError;
use App\Modules\Booking\Support\SessionTime;
use App\Modules\Consent\Models\LegalDocument;
use App\Modules\Consent\Services\ConsentService;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Models\GiftCertificate;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gift certificates (SITE-20, DEC-43, SEQ-18, ST-18 with the DM-14 fix): a separate payment with its own receipt;
 * an individual code (only its hash is stored) is emailed after payment; activation in CL-07 credits the WHOLE
 * nominal to the client balance as certificate funds (spendable, never withdrawn). A certificate is a prepayment,
 * not a discount: the psychologist's share is not reduced.
 */
class GiftCertificateService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private PaymentService $payments,
        private BalanceService $balance,
        private ConsentService $consents,
        private Notifier $notifier,
    ) {}

    /** @return array{nominals: list<int>, validity_days: int} */
    public function options(): array
    {
        return [
            'nominals' => array_values(array_map('intval', (array) Settings::get('P-GIFT-NOMINALS'))),
            'validity_days' => Settings::int('P-GIFT-VALIDITY'),
        ];
    }

    /**
     * @param  array{nominal: int, buyer_email: string, buyer_name?: string|null, recipient_name?: string|null, recipient_email?: string|null, message?: string|null, send_to?: string}  $data
     */
    public function purchase(?User $buyer, array $data): Payment
    {
        $nominal = (int) $data['nominal'];
        if (! in_array($nominal, $this->options()['nominals'], true)) {
            throw ValidationException::withMessages(['nominal' => 'Выберите номинал из списка.']);
        }
        $sendTo = ($data['send_to'] ?? 'recipient') === 'buyer' || empty($data['recipient_email']) ? 'buyer' : 'recipient';

        $certificate = DB::transaction(function () use ($buyer, $data, $nominal, $sendTo) {
            $c = new GiftCertificate;
            $c->forceFill([
                'nominal' => $nominal,
                'buyer_user_id' => $buyer?->id,
                'buyer_email' => mb_strtolower(trim($data['buyer_email'])),
                'buyer_name' => $data['buyer_name'] ?? $buyer?->name,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_email' => isset($data['recipient_email']) ? mb_strtolower(trim((string) $data['recipient_email'])) : null,
                'message' => $data['message'] ?? null,
                'send_to' => $sendTo,
                'status' => 'awaiting_payment',
            ])->save();
            $c->recordInitialState($buyer?->id, ['nominal' => $nominal]);
            $this->consents->accept($buyer, LegalDocument::PERSONAL_DATA, $c, $c->buyer_email, 'accept_personal_data');
            $this->consents->accept($buyer, LegalDocument::OFFER, $c, $c->buyer_email, 'accept_offer');

            return $c;
        });

        $payment = $this->payments->startPayerPayment(
            $buyer,
            'certificate',
            $certificate,
            $nominal,
            'Подарочный сертификат ТЕТА на '.Money::format($nominal),
            '/gift/success?certificate='.$certificate->id,
            ReceiptService::forCertificate($nominal, $certificate->buyer_email),
            false,
            ['certificate_id' => $certificate->id],
        );
        $certificate->forceFill(['payment_id' => $payment->id])->save();

        return $payment;
    }

    /** Purpose "certificate": paid → code issued and emailed (inside the payment transaction). */
    public function onPaid(Payment $payment): void
    {
        $c = GiftCertificate::whereKey($payment->meta('certificate_id'))->lockForUpdate()->first();
        if (! $c || $c->status !== 'awaiting_payment') {
            return;
        }
        $code = $this->generateCode();
        $c->transitionTo('paid', $c->buyer_user_id, 'payment succeeded', [
            'code' => null,
            'code_hash' => GiftCertificate::hashCode($code),
            'code_hint' => substr(GiftCertificate::normalizeCode($code), -4),
            'payment_id' => $payment->id,
            'valid_until' => now()->addDays(Settings::int('P-GIFT-VALIDITY')),
            'sent_at' => now(),
        ], ['nominal' => $c->nominal, 'payment_id' => $payment->id]);

        $vars = [
            'nominal' => Money::format((int) $c->nominal),
            'code' => $code,
            'valid_until' => SessionTime::date($c->valid_until, (string) config('platform.timezone')),
            'buyer' => $c->buyer_name ?: 'Ваш близкий',
            'recipient' => $c->recipient_name ? ', '.$c->recipient_name : '',
            'message' => $c->message ?: '',
        ];
        $to = $c->send_to === 'recipient' && $c->recipient_email ? $c->recipient_email : $c->buyer_email;
        DB::afterCommit(function () use ($to, $vars, $c) {
            $this->notifier->sendToEmail($to, 'pay.certificate_code', $vars, '/client/payments?certificate=1', 'Активировать сертификат');
            $this->notifier->sendToEmail($c->buyer_email, 'pay.certificate_purchased', [
                ...$vars,
                'code' => $c->send_to === 'buyer' || ! $c->recipient_email ? $vars['code'] : 'отправлен получателю на '.$c->recipient_email,
            ], '/gift');
        });
    }

    public function onDeclined(Payment $payment): void
    {
        $c = GiftCertificate::whereKey($payment->meta('certificate_id'))->lockForUpdate()->first();
        if ($c && $c->status === 'awaiting_payment') {
            $c->transitionTo('unpaid', reason: $payment->error_code ?? 'declined');
        }
    }

    /** CL-07: the client enters the code — the whole nominal becomes certificate funds on the balance. */
    public function activate(User $client, string $code): GiftCertificate
    {
        $hash = GiftCertificate::hashCode($code);
        $c = DB::transaction(function () use ($client, $hash) {
            $c = GiftCertificate::where('code_hash', $hash)->lockForUpdate()->first();
            if (! $c || ! in_array($c->status, ['paid', 'activated', 'expired'], true)) {
                BookingError::fail('Код сертификата не найден. Проверьте, что он введён без ошибок.', 'not_found', 'code');
            }
            if ($c->status === 'activated') {
                BookingError::fail('Этот сертификат уже активирован.', 'already_activated', 'code', 409);
            }
            if ($c->status === 'expired' || ($c->valid_until && $c->valid_until <= now())) {
                BookingError::fail('Срок действия сертификата истёк.', 'expired', 'code');
            }
            $op = $this->balance->credit($client->id, (int) $c->nominal, 'certificate', $c, null, true, 'Сертификат •••• '.$c->code_hint, $client->id);
            $c->transitionTo('activated', $client->id, 'activated', [
                'activated_by' => $client->id,
                'activated_at' => now(),
                'balance_operation_id' => $op?->id,
            ], ['nominal' => $c->nominal, 'user_id' => $client->id]);

            return $c;
        });
        $this->notifier->send($client, 'pay.certificate_activated', ['nominal' => Money::format((int) $c->nominal)], '/client/payments');

        return $c;
    }

    /** P-GIFT-VALIDITY passed without activation (daily). */
    public function expire(): int
    {
        $count = 0;
        foreach (GiftCertificate::where('status', 'paid')->where('valid_until', '<=', now())->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count) {
                $c = GiftCertificate::whereKey($id)->lockForUpdate()->first();
                if ($c && $c->status === 'paid') {
                    $c->transitionTo('expired', reason: 'validity ended', attributes: ['expired_at' => now()]);
                    $count++;
                }
            });
        }

        return $count;
    }

    public static function toApi(GiftCertificate $c): array
    {
        return [
            'id' => $c->id,
            'status' => $c->status,
            'nominal' => (int) $c->nominal,
            'code_hint' => $c->code_hint,
            'recipient_name' => $c->recipient_name,
            'valid_until' => $c->valid_until?->toIso8601String(),
            'activated_at' => $c->activated_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function generateCode(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = 'TETA-'.implode('-', str_split($raw, 4));
        } while (GiftCertificate::where('code_hash', GiftCertificate::hashCode($code))->exists());

        return $code;
    }
}
