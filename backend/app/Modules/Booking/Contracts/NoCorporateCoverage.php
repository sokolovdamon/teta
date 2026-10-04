<?php

namespace App\Modules\Booking\Contracts;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Corporate\Models\CorporateParticipation;

/** Default until the B2B module binds its implementation. */
class NoCorporateCoverage implements CorporateCoverage
{
    public function coverFor(User $client, string $format): ?CorporateParticipation
    {
        return null;
    }

    public function consume(TherapySession $session): void {}

    public function release(TherapySession $session): void {}
}
