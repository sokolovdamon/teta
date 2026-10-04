<?php

namespace App\Modules\Promo\Contracts;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Psychologists\Models\Psychologist;

/**
 * PROMO service used by BOOK at booking time (Э8, ST-17). The discount reduces only the platform share:
 * accruals are always 70 % of the full price (DEC-57).
 *
 * quote()   — validate a code for a booking and return the discount (throws ValidationException with field "promo_code");
 * reserve() — reserve the use for the created session (counts against limits);
 * consume() — the session was charged (or paid otherwise): the use becomes final;
 * restore() — the session was cancelled before charge: the use is returned (BR-PROMO-09).
 */
interface PromoCodes
{
    /** @return array{promo_code_id: string, code: string, discount: int} */
    public function quote(string $code, User $client, Psychologist $psychologist, string $format, int $price): array;

    public function reserve(string $code, User $client, TherapySession $session): void;

    public function consume(TherapySession $session): void;

    public function restore(TherapySession $session): void;
}
