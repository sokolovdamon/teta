<?php

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Times are stored in UTC (sequences_states.md, rule 1). Eloquent formats a Carbon value in its own timezone,
 * so a 11:00 Europe/Moscow instance would be saved as "11:00" and read back as 11:00 UTC. This trait converts
 * every date assigned to a model to UTC first. Use it in every model together with HasUuids.
 */
trait UtcDates
{
    public function fromDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            $value = CarbonImmutable::instance($value)->utc();
        }

        return parent::fromDateTime($value);
    }
}
