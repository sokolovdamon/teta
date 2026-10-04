<?php

namespace App\Support;

/** Money is stored as integer kopecks everywhere. */
final class Money
{
    public static function format(int $kopecks): string
    {
        $rub = intdiv($kopecks, 100);
        $kop = abs($kopecks % 100);
        $formatted = number_format($rub, 0, ',', ' ');

        return $kop ? sprintf('%s,%02d ₽', $formatted, $kop) : "{$formatted} ₽";
    }

    /** Percentage share rounded half up to whole kopecks, e.g. share(350000, 70) = 245000. */
    public static function share(int $kopecks, int $percent): int
    {
        return intdiv($kopecks * $percent + 50, 100);
    }

    public static function fromRubles(int|float $rubles): int
    {
        return (int) round($rubles * 100);
    }
}
