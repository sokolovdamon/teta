<?php

namespace App\Modules\Booking\Contracts;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Corporate\Models\CorporateParticipation;

/**
 * B2B (11.1, DEC-25): whether a booking is covered by the client's corporate program limit.
 * BOOK asks coverFor() when booking; if a participation is returned, the session is paid by the company
 * (no charge task, no receipt — BR-B2B-05). release() restores the limit when such a session is cancelled
 * or refunded (BR-CANC-11). Over the limit the client pays with a personal card.
 */
interface CorporateCoverage
{
    public function coverFor(User $client, string $format): ?CorporateParticipation;

    public function consume(TherapySession $session): void;

    public function release(TherapySession $session): void;
}
