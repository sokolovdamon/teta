<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Payments\Gateway\ChargeRequest;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\PaymentRequest;
use App\Modules\Payments\Gateway\ReceiptData;
use App\Modules\Payments\Gateway\RefundRequest;
use App\Modules\Payments\Gateway\WebhookEvent;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentRefund;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ST-03: every payment is an operation through the gateway with an idempotency key. The Payment row is committed
 * before the gateway is called; the result (synchronous response or webhook, whichever comes first) is applied
 * exactly once under a row lock, so a late or repeated result can never charge or credit twice (BR-PAY-06).
 */
class PaymentService
{
    public function __construct(
        private PaymentGateway $gateway,
        private ReceiptService $receipts,
        private CardService $cards,
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    /**
     * New payment row in status "created". The idempotency key defaults to "pay:{id}".
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createPayment(?User $user, string $purpose, ?Model $payable, int $amount, string $description, ?ReceiptData $receipt = null, array $metadata = [], bool $withPayer = false, ?PaymentMethod $method = null, ?string $idempotencyKey = null): Payment
    {
        return DB::transaction(function () use ($user, $purpose, $payable, $amount, $description, $receipt, $metadata, $withPayer, $method, $idempotencyKey) {
            $id = (string) Str::uuid7();
            $payment = new Payment;
            $payment->forceFill([
                'id' => $id,
                'user_id' => $user?->id,
                'purpose' => $purpose,
                'payable_type' => $payable?->getMorphClass(),
                'payable_id' => $payable?->getKey(),
                'amount' => $amount,
                'status' => 'created',
                'gateway' => $this->gateway->name(),
                'idempotency_key' => $idempotencyKey ?? 'pay:'.$id,
                'payment_method_id' => $method?->id,
                'card_mask' => $method?->card_mask,
                'with_payer' => $withPayer,
                'description' => mb_substr($description, 0, 255),
                'metadata' => [...$metadata, 'receipt' => $receipt ? ReceiptService::toArray($receipt) : null],
            ])->save();
            $payment->recordInitialState($user?->id, ['purpose' => $purpose, 'amount' => $amount]);

            return $payment;
        });
    }

    /**
     * Payment with the payer present (late booking, another card, certificate, event): returns the payment with
     * the confirmation URL of the provider's (emulator's) form. "next" is where /pay/return sends the payer.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function startPayerPayment(?User $user, string $purpose, ?Model $payable, int $amount, string $description, ?string $nextPath, ?ReceiptData $receipt, bool $saveCard = false, array $metadata = []): Payment
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма оплаты должна быть больше нуля.']);
        }
        $payment = $this->createPayment($user, $purpose, $payable, $amount, $description, $receipt, [...$metadata, 'save_card' => $saveCard, 'next' => $nextPath], true);

        return $this->beginCheckout($payment);
    }

    /** Open the provider's (emulator's) payment form for an existing payment row with the payer present. */
    public function beginCheckout(Payment $payment): Payment
    {
        $returnUrl = rtrim((string) config('app.frontend_url'), '/').'/pay/return?payment='.$payment->id;
        $payment->forceFill(['return_url' => $returnUrl, 'with_payer' => true])->save();

        $op = $this->gateway->createPayment(new PaymentRequest(
            $payment->idempotency_key,
            $payment->amount,
            (string) $payment->description,
            $returnUrl,
            ReceiptService::fromArray($payment->meta('receipt')),
            (bool) $payment->meta('save_card', false),
            $payment->user_id,
        ));

        return $this->applyResult($payment, $op);
    }

    /** Charge a saved card without the payer. The payment row must already be committed. */
    public function chargeSaved(Payment $payment, PaymentMethod $method): Payment
    {
        $op = $this->gateway->chargeToken(new ChargeRequest(
            $payment->idempotency_key,
            $method->token,
            $payment->amount,
            (string) $payment->description,
            ReceiptService::fromArray($payment->meta('receipt')),
        ));

        return $this->applyResult($payment, $op);
    }

    /** Query the gateway for an operation whose result is unknown (timeout, lost webhook) and apply it. */
    public function resolve(Payment $payment): Payment
    {
        if (! in_array($payment->status, ['created', 'requires_3ds', 'unknown'], true)) {
            return $payment;
        }

        return $this->applyResult($payment, $this->gateway->status($payment->idempotency_key));
    }

    public function handleWebhook(WebhookEvent $event): void
    {
        $payment = Payment::where('idempotency_key', $event->idempotencyKey)->first()
            ?? ($event->operation->gatewayId ? Payment::where('gateway_payment_id', $event->operation->gatewayId)->first() : null);
        if ($payment) {
            $this->applyResult($payment, $event->operation);
        }
    }

    /** Apply a gateway result exactly once (row lock + status check); purpose handlers run in the same transaction. */
    public function applyResult(Payment $payment, GatewayOperation $op): Payment
    {
        return DB::transaction(function () use ($payment, $op) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $open = in_array($p->status, ['created', 'requires_3ds', 'unknown'], true);
            $handler = PaymentPurposes::for($p->purpose);

            switch ($op->status) {
                case GatewayOperation::SUCCEEDED:
                    if (! $open) {
                        break;
                    }
                    $p->transitionTo('succeeded', reason: 'gateway', attributes: [
                        'paid_at' => now(),
                        'gateway_payment_id' => $op->gatewayId ?? $p->gateway_payment_id,
                        'card_mask' => $op->card['mask'] ?? $p->card_mask,
                        'confirmation_url' => null,
                    ], context: ['purpose' => $p->purpose, 'amount' => $p->amount]);
                    $this->receipts->income($p, $op->raw['receipt'] ?? null);
                    if ($p->meta('save_card') && $op->card && $p->user_id) {
                        $this->cards->store($p->user_id, $op->card, 'payment');
                    }
                    $handler?->succeeded($p);
                    break;

                case GatewayOperation::DECLINED:
                    if (! $open) {
                        break;
                    }
                    $p->transitionTo('declined', reason: $op->errorCode, attributes: [
                        'gateway_payment_id' => $op->gatewayId ?? $p->gateway_payment_id,
                        'error_code' => $op->errorCode,
                        'error_category' => $op->errorCategory ?? 'no_retry',
                        'error_message' => self::declineMessage($op->errorCode),
                        'confirmation_url' => null,
                    ], context: ['purpose' => $p->purpose, 'error_category' => $op->errorCategory]);
                    $handler?->declined($p);
                    break;

                case GatewayOperation::UNKNOWN:
                    if ($p->status === 'created') {
                        $p->transitionTo('unknown', reason: 'timeout', attributes: ['gateway_payment_id' => $op->gatewayId ?? $p->gateway_payment_id]);
                        $handler?->unknown($p);
                    }
                    break;

                case GatewayOperation::REQUIRES_ACTION:
                    if ($p->status === 'created') {
                        $p->transitionTo('requires_3ds', reason: 'payer confirmation', attributes: [
                            'gateway_payment_id' => $op->gatewayId,
                            'confirmation_url' => $op->confirmationUrl,
                        ]);
                    }
                    break;
            }

            return $p;
        });
    }

    /**
     * Refund to the card of a successful payment (withdrawal of the balance remainder, manual refund by an admin,
     * a payment that arrived for an already cancelled booking). Ordinary refunds go to the cabinet balance (Q-52).
     */
    public function refundToCard(Payment $payment, int $amount, ?Model $source, string $reason, ?string $actorId = null): PaymentRefund
    {
        $refund = DB::transaction(function () use ($payment, $amount, $source, $reason, $actorId) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $pending = (int) PaymentRefund::where('payment_id', $p->id)->where('status', 'pending')->sum('amount');
            if (! in_array($p->status, ['succeeded', 'partially_refunded'], true) || $amount <= 0 || $amount > $p->refundable() - $pending) {
                throw ValidationException::withMessages(['amount' => 'Сумма возврата больше доступной по этому платежу.']);
            }
            $id = (string) Str::uuid7();
            $refund = new PaymentRefund;
            $refund->forceFill([
                'id' => $id,
                'payment_id' => $p->id,
                'amount' => $amount,
                'status' => 'pending',
                'idempotency_key' => 'refund:'.$id,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'reason' => $reason,
                'created_by' => $actorId,
            ])->save();

            return $refund;
        });

        $data = $payment->meta('receipt');
        $receipt = $data ? new ReceiptData((string) $data['calculation_method'], [['name' => 'Возврат', 'amount' => $amount, 'quantity' => 1]], $data['customer_email'] ?? null) : null;
        $op = $this->gateway->refund(new RefundRequest($refund->idempotency_key, (string) $payment->gateway_payment_id, $amount, $receipt));
        $this->applyRefundResult($refund, $op);

        return $refund->fresh();
    }

    public function handleRefundWebhook(WebhookEvent $event): void
    {
        $refund = PaymentRefund::where('idempotency_key', $event->idempotencyKey)->first();
        if ($refund) {
            $this->applyRefundResult($refund, $event->operation);
        }
    }

    public function resolveRefund(PaymentRefund $refund): void
    {
        if ($refund->status === 'pending') {
            $this->applyRefundResult($refund, $this->gateway->status($refund->idempotency_key));
        }
    }

    public function applyRefundResult(PaymentRefund $refund, GatewayOperation $op): void
    {
        DB::transaction(function () use ($refund, $op) {
            $r = PaymentRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                return;
            }
            if ($op->status === GatewayOperation::SUCCEEDED) {
                $p = Payment::whereKey($r->payment_id)->lockForUpdate()->firstOrFail();
                $r->forceFill(['status' => 'succeeded', 'gateway_refund_id' => $op->gatewayId])->save();
                $refunded = $p->refunded_amount + $r->amount;
                $p->transitionTo($refunded >= $p->amount ? 'refunded' : 'partially_refunded', $r->created_by, $r->reason, ['refunded_amount' => $refunded], ['refund_id' => $r->id, 'amount' => $r->amount]);
                $this->receipts->refund($r, $op->raw['receipt'] ?? null);
                Outbox::record('pay.refund.completed', $r, ['payment_id' => $p->id, 'amount' => $r->amount, 'reason' => $r->reason], $r->created_by);
            } elseif ($op->status === GatewayOperation::DECLINED) {
                $r->forceFill(['status' => 'failed', 'error_code' => $op->errorCode ?? 'declined'])->save();
                Outbox::record('pay.refund.failed', $r, ['payment_id' => $r->payment_id, 'amount' => $r->amount, 'error_code' => $op->errorCode], $r->created_by);
            } else {
                return;
            }
            if ($r->source_type === (new ClientBalanceOperation)->getMorphClass()) {
                app(WithdrawalService::class)->refundSettled($r);
            }
        });
    }

    public static function declineMessage(?string $code): string
    {
        return match ($code) {
            'insufficient_funds' => 'Недостаточно средств на карте.',
            'expired_card' => 'Срок действия карты истёк.',
            'authentication_required' => 'Банк требует подтверждения оплаты: оплатите с подтверждением 3-D Secure.',
            'authentication_failed' => 'Оплата не подтверждена в 3-D Secure.',
            'payer_cancelled' => 'Оплата отменена.',
            'invalid_token', 'no_card' => 'Карта недоступна: привяжите другую карту.',
            'not_found' => 'Операция не найдена у платёжного сервиса.',
            default => 'Банк отклонил оплату.',
        };
    }
}
