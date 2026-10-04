<?php

namespace App\Modules\Payments\Gateway\Emulator;

/**
 * Deterministic test cards of the payment emulator (DEC-38). The behaviour depends only on the card number,
 * so tests and demos are reproducible. Any other number that passes the Luhn check behaves as a successful card.
 * Amounts ending in 13 kopecks emulate a timeout: the call returns "unknown", the operation is still processed
 * on the emulator side and its webhook is lost — the platform must recover the result with a status query.
 */
final class EmulatorCards
{
    public const SUCCESS = 'success';

    public const DECLINED = 'declined';

    public const INSUFFICIENT = 'insufficient';

    public const EXPIRED = 'expired';

    public const THREE_DS = '3ds';

    public const REFUND_FAIL = 'refund_fail';

    /** @var list<array{number: string, behavior: string, title: string}> */
    public const CARDS = [
        ['number' => '4111111111111111', 'behavior' => self::SUCCESS, 'title' => 'Оплата проходит'],
        ['number' => '2200000000000004', 'behavior' => self::SUCCESS, 'title' => 'Оплата проходит (МИР)'],
        ['number' => '4000000000000002', 'behavior' => self::DECLINED, 'title' => 'Отказ банка, повторять бессмысленно (no_retry)'],
        ['number' => '4000000000009995', 'behavior' => self::INSUFFICIENT, 'title' => 'Недостаточно средств, повтор позже (retry)'],
        ['number' => '4000000000000069', 'behavior' => self::EXPIRED, 'title' => 'Срок действия карты истёк, нужна другая карта (new_card)'],
        ['number' => '4000000000003220', 'behavior' => self::THREE_DS, 'title' => 'Требуется 3-D Secure: подтвердите или отклоните; списание без плательщика отклоняется (new_card)'],
        ['number' => '4000000000000077', 'behavior' => self::REFUND_FAIL, 'title' => 'Оплата проходит, возврат на карту отклоняется'],
    ];

    public const TIMEOUT_KOPECKS = 13;

    public static function digits(string $number): string
    {
        return preg_replace('/\D/', '', $number) ?? '';
    }

    public static function behavior(string $number): ?string
    {
        $digits = self::digits($number);
        foreach (self::CARDS as $card) {
            if ($card['number'] === $digits) {
                return $card['behavior'];
            }
        }

        return self::luhn($digits) ? self::SUCCESS : null;
    }

    public static function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    public static function brand(string $number): string
    {
        $digits = self::digits($number);

        return match (true) {
            str_starts_with($digits, '220') => 'МИР',
            str_starts_with($digits, '4') => 'Visa',
            str_starts_with($digits, '5') => 'Mastercard',
            default => 'Карта',
        };
    }

    public static function mask(string $number): string
    {
        return '•••• '.substr(self::digits($number), -4);
    }

    /**
     * Result of a charge for the behaviour.
     *
     * @return array{0: string, 1: string|null, 2: string|null} [status, error code, error category]
     */
    public static function outcome(string $behavior, bool $payerPresent): array
    {
        return match ($behavior) {
            self::DECLINED => ['declined', 'card_declined', 'no_retry'],
            self::INSUFFICIENT => ['declined', 'insufficient_funds', 'retry'],
            self::EXPIRED => ['declined', 'expired_card', 'new_card'],
            self::THREE_DS => $payerPresent ? ['succeeded', null, null] : ['declined', 'authentication_required', 'new_card'],
            default => ['succeeded', null, null],
        };
    }

    public static function isTimeout(int $amount): bool
    {
        return $amount % 100 === self::TIMEOUT_KOPECKS;
    }

    /** @return list<array{number: string, title: string}> */
    public static function catalog(): array
    {
        return array_map(fn ($c) => [
            'number' => trim(chunk_split($c['number'], 4, ' ')),
            'title' => $c['title'],
            'behavior' => $c['behavior'],
        ], self::CARDS);
    }
}
