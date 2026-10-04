<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Production calendar of the RF. Regular week is Mon–Fri; calendar_days overrides holidays and
 * transferred working days. Used for the 14-working-day complaint deadline (DEC-23) and SLAs.
 */
class WorkingDays
{
    public static function isWorking(CarbonImmutable $date): bool
    {
        $override = DB::table('calendar_days')->where('date', $date->toDateString())->value('is_working');
        if ($override !== null) {
            return (bool) $override;
        }

        return ! $date->isWeekend();
    }

    /** Date that is $days working days after $from (the start day itself is not counted). */
    public static function add(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $date = $from->startOfDay();
        $left = $days;
        while ($left > 0) {
            $date = $date->addDay();
            if (self::isWorking($date)) {
                $left--;
            }
        }

        return $date;
    }

    /** Working days between two dates, excluding $from and including $to. */
    public static function between(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $count = 0;
        $date = $from->startOfDay();
        $end = $to->startOfDay();
        while ($date < $end) {
            $date = $date->addDay();
            if (self::isWorking($date)) {
                $count++;
            }
        }

        return $count;
    }
}
