<?php

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\PostgresConnection;

/** Query bindings with dates in any timezone are converted to UTC before formatting (see UtcDates). */
class UtcPostgresConnection extends PostgresConnection
{
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = CarbonImmutable::instance($value)->utc();
            }
        }

        return parent::prepareBindings($bindings);
    }
}
