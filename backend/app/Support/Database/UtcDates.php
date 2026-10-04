<?php

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Times are stored in UTC (sequences_states.md, rule 1). Eloquent formats a date in its own timezone, so a
 * 11:00 Europe/Moscow value (a Carbon instance or an ISO string with an offset) would be saved as "11:00" and read
 * back as 11:00 UTC. This trait converts every date assigned to a model to UTC first. Use it in every model.
 */
trait UtcDates
{
    public function fromDateTime($value)
    {
        if ($value === null || $value === '') {
            return parent::fromDateTime($value);
        }
        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::instance($this->asDateTime($value));

        return parent::fromDateTime($date->utc());
    }
}
