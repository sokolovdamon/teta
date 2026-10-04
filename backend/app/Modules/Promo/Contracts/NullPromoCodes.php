<?php

namespace App\Modules\Promo\Contracts;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Validation\ValidationException;

/** Placeholder until the PROMO module binds its implementation. */
class NullPromoCodes implements PromoCodes
{
    public function quote(string $code, User $client, Psychologist $psychologist, string $format, int $price): array
    {
        throw ValidationException::withMessages(['promo_code' => 'Промокод не найден.']);
    }

    public function reserve(string $code, User $client, TherapySession $session): void {}

    public function consume(TherapySession $session): void {}

    public function restore(TherapySession $session): void {}
}
