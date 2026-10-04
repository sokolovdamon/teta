<?php

namespace App\Modules\Booking\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Time in the recipient's timezone with the zone stated (BR-SCHED-01, BR-NOTIF-05, DEC-16):
 * "12 октября, понедельник, 14:00 (МСК)".
 */
final class SessionTime
{
    public static function format(DateTimeInterface|string|null $at, ?string $timezone): string
    {
        if ($at === null) {
            return '—';
        }
        $tz = $timezone ?: config('platform.timezone');
        $local = CarbonImmutable::parse($at)->setTimezone($tz)->locale('ru');

        return $local->isoFormat('D MMMM, dddd, HH:mm').' ('.self::zoneLabel($tz, $local).')';
    }

    public static function date(DateTimeInterface|string|null $at, ?string $timezone): string
    {
        if ($at === null) {
            return '—';
        }

        return CarbonImmutable::parse($at)->setTimezone($timezone ?: config('platform.timezone'))->locale('ru')->isoFormat('D MMMM YYYY');
    }

    public static function zoneLabel(string $timezone, ?CarbonImmutable $at = null): string
    {
        if ($timezone === 'Europe/Moscow') {
            return 'МСК';
        }
        $offset = ($at ?? CarbonImmutable::now())->setTimezone($timezone)->format('P');

        return 'UTC'.$offset;
    }

    public static function formatLabel(string $format): string
    {
        return $format === 'pair' ? 'парная сессия' : 'индивидуальная сессия';
    }
}
