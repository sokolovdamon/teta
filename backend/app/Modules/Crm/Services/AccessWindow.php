<?php

namespace App\Modules\Crm\Services;

use Carbon\CarbonImmutable;

/**
 * Diary access of a psychologist to a client (DEC-41, DM-08): data recorded before $until is visible;
 * $until = null means no restriction (the psychologist is the client's current psychologist).
 */
final readonly class AccessWindow
{
    public function __construct(public ?CarbonImmutable $until) {}

    public function isRestricted(): bool
    {
        return $this->until !== null;
    }

    /** @return array{restricted: bool, until: string|null} */
    public function toApi(): array
    {
        return ['restricted' => $this->isRestricted(), 'until' => $this->until?->toIso8601String()];
    }
}
