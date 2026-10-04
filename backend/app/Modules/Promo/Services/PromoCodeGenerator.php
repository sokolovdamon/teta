<?php

namespace App\Modules\Promo\Services;

use App\Modules\Promo\Models\PromoCode;

/** Unique human-friendly codes without look-alike characters (no 0/O, 1/I/L). */
class PromoCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function one(?string $prefix = null, int $length = 8): string
    {
        return $this->many(1, $prefix, $length)[0];
    }

    /** @return list<string> */
    public function many(int $count, ?string $prefix = null, int $length = 8): array
    {
        $prefix = $prefix ? PromoCode::normalize($prefix).'-' : '';
        $codes = [];
        while (count($codes) < $count) {
            $candidates = [];
            for ($i = 0; $i < max(10, ($count - count($codes)) * 2); $i++) {
                $candidates[$prefix.$this->random($length)] = true;
            }
            $candidates = array_keys(array_diff_key($candidates, array_flip($codes)));
            $taken = PromoCode::whereIn('code', $candidates)->pluck('code')->all();
            foreach (array_diff($candidates, $taken) as $code) {
                if (count($codes) >= $count) {
                    break;
                }
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function random(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
