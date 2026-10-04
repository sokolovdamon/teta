<?php

namespace App\Modules\Payments\Gateway\Emulator;

use App\Modules\Payments\Gateway\BindingRequest;
use App\Modules\Payments\Gateway\ChargeRequest;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\InvalidWebhook;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\PaymentRequest;
use App\Modules\Payments\Gateway\PayoutRequest;
use App\Modules\Payments\Gateway\ReceiptData;
use App\Modules\Payments\Gateway\RefundRequest;
use App\Modules\Payments\Gateway\WebhookEvent;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Test emulator of the payment service (DEC-38): the same statuses, decline categories, 3-D Secure, receipts
 * and signed webhooks as a real provider. Operations are stored by idempotency key; a repeated key returns the
 * stored operation and never creates a second one. Behaviour of cards — EmulatorCards.
 */
class EmulatorGateway implements PaymentGateway
{
    public function __construct(private EmulatorWebhooks $webhooks) {}

    public function name(): string
    {
        return 'emulator';
    }

    public function createBinding(BindingRequest $request): GatewayOperation
    {
        $op = $this->firstOrCreate($request->idempotencyKey, [
            'kind' => 'binding',
            'status' => 'requires_action',
            'amount' => $request->verificationAmount,
            'user_id' => $request->userId,
            'return_url' => $request->returnUrl,
            'save_card' => true,
            'description' => 'Привязка карты: проверочная операция на '.Money::format($request->verificationAmount).' сразу отменяется',
        ]);

        return $this->toGateway($op);
    }

    public function chargeToken(ChargeRequest $request): GatewayOperation
    {
        if ($existing = $this->find($request->idempotencyKey)) {
            return $this->toGateway($existing);
        }

        $card = EmulatorCard::where('token', $request->token)->first();
        [$status, $code, $category] = $card ? EmulatorCards::outcome($card->behavior, false) : ['declined', 'invalid_token', 'new_card'];
        $timeout = EmulatorCards::isTimeout($request->amount);

        $op = $this->firstOrCreate($request->idempotencyKey, [
            'kind' => 'charge',
            'status' => $status,
            'amount' => $request->amount,
            'description' => $request->description,
            'user_id' => $card?->user_id,
            'token' => $request->token,
            'card_mask' => $card?->card_mask,
            'card_brand' => $card?->card_brand,
            'exp_month' => $card?->exp_month,
            'exp_year' => $card?->exp_year,
            'behavior' => $card?->behavior,
            'error_code' => $code,
            'error_category' => $category,
            'receipt' => $this->receiptArray($request->receipt),
            'fiscal' => $status === 'succeeded' ? $this->fiscal($request->receipt, $request->amount, 'income') : null,
            'webhook_suppressed' => $timeout,
        ], $created);

        if (! $created) {
            return $this->toGateway($op);
        }
        if ($timeout) {
            // The call "times out": the operation is processed, but neither the response nor the webhook arrives.
            return new GatewayOperation(GatewayOperation::UNKNOWN, $op->id, raw: ['timeout' => true]);
        }
        $this->webhooks->send($op, $status === 'succeeded' ? 'payment.succeeded' : 'payment.declined');

        return $this->toGateway($op);
    }

    public function createPayment(PaymentRequest $request): GatewayOperation
    {
        $op = $this->firstOrCreate($request->idempotencyKey, [
            'kind' => 'payment',
            'status' => 'requires_action',
            'amount' => $request->amount,
            'description' => $request->description,
            'return_url' => $request->returnUrl,
            'user_id' => $request->userId,
            'save_card' => $request->saveCard,
            'receipt' => $this->receiptArray($request->receipt),
        ]);

        return $this->toGateway($op);
    }

    public function refund(RefundRequest $request): GatewayOperation
    {
        if ($existing = $this->find($request->idempotencyKey)) {
            return $this->toGateway($existing);
        }

        [$op, $created] = DB::transaction(function () use ($request) {
            $parent = EmulatorOperation::whereKey($request->gatewayPaymentId)->whereIn('kind', ['charge', 'payment'])->lockForUpdate()->first();
            if ($existing = $this->find($request->idempotencyKey)) {
                return [$existing, false];
            }
            [$status, $code, $category] = match (true) {
                ! $parent || $parent->status !== 'succeeded' => ['declined', 'payment_not_found', 'no_retry'],
                $request->amount <= 0 || $request->amount > $parent->amount - $parent->refunded_amount => ['declined', 'amount_exceeds_payment', 'no_retry'],
                $parent->behavior === EmulatorCards::REFUND_FAIL => ['declined', 'refund_rejected', 'no_retry'],
                default => ['succeeded', null, null],
            };
            if ($status === 'succeeded') {
                $parent->forceFill(['refunded_amount' => $parent->refunded_amount + $request->amount])->save();
            }

            $op = $this->firstOrCreate($request->idempotencyKey, [
                'kind' => 'refund',
                'status' => $status,
                'amount' => $request->amount,
                'parent_id' => $parent?->id,
                'card_mask' => $parent?->card_mask,
                'description' => 'Возврат',
                'error_code' => $code,
                'error_category' => $category,
                'receipt' => $this->receiptArray($request->receipt),
                'fiscal' => $status === 'succeeded' ? $this->fiscal($request->receipt, $request->amount, 'income_return') : null,
                'webhook_suppressed' => EmulatorCards::isTimeout($request->amount),
            ], $created);

            return [$op, $created];
        });

        if (! $created) {
            return $this->toGateway($op);
        }
        if ($op->webhook_suppressed) {
            return new GatewayOperation(GatewayOperation::UNKNOWN, $op->id, raw: ['timeout' => true]);
        }
        $this->webhooks->send($op, $op->status === 'succeeded' ? 'refund.succeeded' : 'refund.failed');

        return $this->toGateway($op);
    }

    public function status(string $idempotencyKey): GatewayOperation
    {
        $op = $this->find($idempotencyKey);
        if (! $op) {
            // "Операции нет" — the platform treats it as declined and may retry with a new key (ST-03).
            return new GatewayOperation(GatewayOperation::DECLINED, errorCode: 'not_found', errorCategory: 'retry');
        }

        return $this->toGateway($op);
    }

    public function payout(PayoutRequest $request): GatewayOperation
    {
        if ($existing = $this->find($request->idempotencyKey)) {
            return $this->toGateway($existing);
        }
        $card = EmulatorCard::where('token', $request->token)->first();
        [$status, $code, $category] = match ($card?->behavior) {
            null => ['declined', 'invalid_token', 'new_card'],
            EmulatorCards::DECLINED => ['declined', 'payout_rejected', 'no_retry'],
            EmulatorCards::EXPIRED => ['declined', 'expired_card', 'new_card'],
            default => ['succeeded', null, null],
        };
        $op = $this->firstOrCreate($request->idempotencyKey, [
            'kind' => 'payout',
            'status' => $status,
            'amount' => $request->amount,
            'description' => $request->description,
            'user_id' => $card?->user_id,
            'token' => $request->token,
            'card_mask' => $card?->card_mask,
            'card_brand' => $card?->card_brand,
            'error_code' => $code,
            'error_category' => $category,
            'webhook_suppressed' => EmulatorCards::isTimeout($request->amount),
        ], $created);

        if ($created && ! $op->webhook_suppressed) {
            $this->webhooks->send($op, $status === 'succeeded' ? 'payout.paid' : 'payout.rejected');
        }
        if ($created && $op->webhook_suppressed) {
            return new GatewayOperation(GatewayOperation::UNKNOWN, $op->id, raw: ['timeout' => true]);
        }

        // A payout is accepted for processing; the final result comes with the webhook (or payoutStatus()).
        return new GatewayOperation(GatewayOperation::PENDING, $op->id, raw: ['kind' => 'payout']);
    }

    public function payoutStatus(string $idempotencyKey): GatewayOperation
    {
        return $this->status($idempotencyKey);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $raw = (string) $request->getContent();
        $signature = (string) $request->header('X-Emulator-Signature', '');
        if ($signature === '' || ! hash_equals(EmulatorWebhooks::sign($raw), $signature)) {
            throw new InvalidWebhook('Подпись вебхука не прошла проверку.');
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['id'], $data['type'], $data['idempotency_key'], $data['object']) || ! is_array($data['object'])) {
            throw new InvalidWebhook('Неверный формат вебхука.');
        }
        $o = $data['object'];
        $operation = new GatewayOperation(
            status: $this->mapStatus((string) ($o['status'] ?? '')),
            gatewayId: $o['id'] ?? null,
            errorCode: $o['error_code'] ?? null,
            errorCategory: $o['error_category'] ?? null,
            card: $o['card'] ?? null,
            raw: ['receipt' => $o['receipt'] ?? null, 'kind' => $o['kind'] ?? null, 'amount' => $o['amount'] ?? null],
        );

        return new WebhookEvent((string) $data['id'], (string) $data['type'], (string) $data['idempotency_key'], $operation, $data);
    }

    public function toGateway(EmulatorOperation $op): GatewayOperation
    {
        $status = $op->kind === 'payout' && $op->status === 'succeeded' ? GatewayOperation::SUCCEEDED : $this->mapStatus($op->status);

        return new GatewayOperation(
            status: $status,
            gatewayId: $op->id,
            confirmationUrl: $status === GatewayOperation::REQUIRES_ACTION ? $op->checkoutUrl() : null,
            errorCode: $op->error_code,
            errorCategory: $op->error_category,
            card: $op->card(),
            raw: ['receipt' => $op->fiscal, 'kind' => $op->kind, 'amount' => $op->amount],
        );
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => GatewayOperation::SUCCEEDED,
            'declined' => GatewayOperation::DECLINED,
            'requires_action', 'awaiting_3ds' => GatewayOperation::REQUIRES_ACTION,
            'pending' => GatewayOperation::PENDING,
            default => GatewayOperation::UNKNOWN,
        };
    }

    private function find(string $key): ?EmulatorOperation
    {
        return EmulatorOperation::where('idempotency_key', $key)->first();
    }

    /** @param  array<string, mixed>  $attributes */
    private function firstOrCreate(string $key, array $attributes, ?bool &$created = null): EmulatorOperation
    {
        $created = false;
        if ($existing = $this->find($key)) {
            return $existing;
        }
        try {
            $op = DB::transaction(fn () => EmulatorOperation::create(['idempotency_key' => $key, ...$attributes]));
            $created = true;

            return $op;
        } catch (UniqueConstraintViolationException) {
            return $this->find($key);
        }
    }

    /** @return array<string, mixed>|null */
    private function receiptArray(?ReceiptData $receipt): ?array
    {
        if (! $receipt) {
            return null;
        }

        return ['calculation_method' => $receipt->calculationMethod, 'items' => $receipt->items, 'customer_email' => $receipt->customerEmail];
    }

    /** Fiscal data of a registered 54-ФЗ receipt (emulated). */
    public function fiscal(?ReceiptData $receipt, int $amount, string $kind): ?array
    {
        if (! $receipt) {
            return null;
        }

        return [
            'kind' => $kind,
            'calculation_method' => $receipt->calculationMethod,
            'amount' => $amount,
            'fn' => '9960440300'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'fd' => (string) random_int(10000, 99999),
            'fpd' => (string) random_int(1000000000, 4294967295),
            'registered_at' => now()->toIso8601String(),
            'customer_email' => $receipt->customerEmail,
        ];
    }
}
