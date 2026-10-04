<?php

namespace App\Modules\Payments\Gateway\Emulator;

use App\Modules\Payments\Gateway\ReceiptData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hosted checkout of the emulator (frontend page /pay/emulator/{id}): the payer enters a test card, passes or
 * declines 3-D Secure, or closes the form. After the result the emulator sends a signed webhook and the payer
 * is returned to the platform's return URL.
 */
class EmulatorCheckout
{
    public function __construct(private EmulatorGateway $gateway, private EmulatorWebhooks $webhooks) {}

    /** @return array<string, mixed> */
    public function show(EmulatorOperation $op): array
    {
        return [
            'id' => $op->id,
            'kind' => $op->kind,
            'status' => $op->status,
            'amount' => $op->amount,
            'description' => $op->description,
            'card_mask' => $op->card_mask,
            'error_code' => $op->error_code,
            'return_url' => $op->isFinal() ? $this->returnUrl($op) : null,
            'test_cards' => EmulatorCards::catalog(),
            'timeout_rule' => 'Сумма, оканчивающаяся на 13 копеек, имитирует обрыв связи: ответ и вебхук теряются, платформа узнаёт результат запросом статуса.',
            'binding_note' => 'При привязке любая тестовая карта проходит проверку (кроме отказа в 3-D Secure); её поведение срабатывает при списаниях.',
        ];
    }

    /** @param  array{card_number: string, exp_month: int, exp_year: int, cvc: string}  $card */
    public function submitCard(EmulatorOperation $op, array $card): EmulatorOperation
    {
        return DB::transaction(function () use ($op, $card) {
            $op = EmulatorOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($op->status !== 'requires_action') {
                throw ValidationException::withMessages(['card_number' => 'Операция уже завершена.']);
            }
            $behavior = EmulatorCards::behavior($card['card_number']);
            if ($behavior === null) {
                throw ValidationException::withMessages(['card_number' => 'Номер карты не прошёл проверку. Используйте тестовую карту.']);
            }
            $expYear = (int) $card['exp_year'] < 100 ? 2000 + (int) $card['exp_year'] : (int) $card['exp_year'];
            $expMonth = (int) $card['exp_month'];
            if ($behavior !== EmulatorCards::EXPIRED && now()->startOfMonth()->greaterThan(now()->setDate($expYear, $expMonth, 1)->startOfMonth())) {
                throw ValidationException::withMessages(['exp_month' => 'Срок действия карты истёк.']);
            }

            $op->forceFill([
                'card_mask' => EmulatorCards::mask($card['card_number']),
                'card_brand' => EmulatorCards::brand($card['card_number']),
                'exp_month' => $expMonth,
                'exp_year' => $expYear,
                'behavior' => $behavior,
            ]);

            if ($behavior === EmulatorCards::THREE_DS) {
                $op->forceFill(['status' => 'awaiting_3ds'])->save();

                return $op;
            }

            return $this->finish($op, $behavior);
        });
    }

    /** 3-D Secure page of the issuer: the payer confirms or declines. */
    public function confirm3ds(EmulatorOperation $op, bool $confirm): EmulatorOperation
    {
        return DB::transaction(function () use ($op, $confirm) {
            $op = EmulatorOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($op->status !== 'awaiting_3ds') {
                throw ValidationException::withMessages(['decision' => 'Подтверждение 3-D Secure сейчас не ожидается.']);
            }

            return $this->finish($op, $confirm ? EmulatorCards::SUCCESS : 'auth_failed');
        });
    }

    /** The payer closed the form: the operation is declined. */
    public function cancel(EmulatorOperation $op): EmulatorOperation
    {
        return DB::transaction(function () use ($op) {
            $op = EmulatorOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if (! in_array($op->status, ['requires_action', 'awaiting_3ds'], true)) {
                return $op;
            }

            return $this->finish($op, 'payer_cancelled');
        });
    }

    public function returnUrl(EmulatorOperation $op): ?string
    {
        if (! $op->return_url) {
            return null;
        }
        $sep = str_contains($op->return_url, '?') ? '&' : '?';

        return $op->return_url.$sep.'status='.($op->status === 'succeeded' ? 'succeeded' : 'declined');
    }

    private function finish(EmulatorOperation $op, string $behavior): EmulatorOperation
    {
        [$status, $code, $category] = match ($behavior) {
            'auth_failed' => ['declined', 'authentication_failed', 'new_card'],
            'payer_cancelled' => ['declined', 'payer_cancelled', 'no_retry'],
            // A binding always passes the verification: the card's behaviour applies to later charges, so the
            // autocharge scenarios (retry, no_retry, new_card) can be reproduced with a bound test card.
            default => $op->kind === 'binding' ? ['succeeded', null, null] : EmulatorCards::outcome($behavior, true),
        };

        $attributes = ['status' => $status, 'error_code' => $code, 'error_category' => $category];
        if ($status === 'succeeded' && ($op->kind === 'binding' || $op->save_card)) {
            $token = 'emu_tok_'.Str::lower(Str::random(32));
            EmulatorCard::create([
                'token' => $token,
                'user_id' => $op->user_id,
                'card_mask' => $op->card_mask,
                'card_brand' => $op->card_brand,
                'exp_month' => $op->exp_month,
                'exp_year' => $op->exp_year,
                'behavior' => $op->behavior === EmulatorCards::THREE_DS ? EmulatorCards::THREE_DS : ($op->behavior ?? EmulatorCards::SUCCESS),
            ]);
            $attributes['token'] = $token;
        }
        if ($status === 'succeeded' && $op->kind === 'payment' && $op->receipt) {
            $receipt = $op->receipt;
            $attributes['fiscal'] = $this->gateway->fiscal(
                new ReceiptData((string) ($receipt['calculation_method'] ?? ''), $receipt['items'] ?? [], $receipt['customer_email'] ?? null),
                $op->amount,
                'income',
            );
        }
        $timeout = $op->kind === 'payment' && EmulatorCards::isTimeout($op->amount);
        $op->forceFill([...$attributes, 'webhook_suppressed' => $timeout])->save();

        if (! $timeout) {
            $prefix = $op->kind === 'binding' ? 'binding' : 'payment';
            $this->webhooks->send($op, $prefix.'.'.($status === 'succeeded' ? 'succeeded' : 'declined'));
        }

        return $op;
    }
}
