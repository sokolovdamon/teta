<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Nearest free slot per psychologist and format for catalog cards and sorting. Cached briefly: the value is only
 * informative — booking always re-checks availability (SlotService::assertBookable). The schedule editor
 * forgets the cache of the psychologist on every change.
 */
class NearestSlots
{
    public const TTL_SECONDS = 120;

    public function __construct(private SlotService $slots) {}

    public function get(Psychologist $p, string $format = 'individual'): ?CarbonImmutable
    {
        $iso = Cache::remember(self::key($p->id, $format), self::TTL_SECONDS, function () use ($p, $format) {
            if (! $this->worksFormat($p, $format)) {
                return '';
            }

            return $this->slots->nearest($p, $format)?->toIso8601ZuluString() ?? '';
        });

        return $iso === '' ? null : CarbonImmutable::parse($iso)->utc();
    }

    public static function forget(string $psychologistId): void
    {
        foreach (['individual', 'pair'] as $format) {
            Cache::forget(self::key($psychologistId, $format));
        }
    }

    private static function key(string $id, string $format): string
    {
        return "catalog.nearest.{$id}.{$format}";
    }

    private function worksFormat(Psychologist $p, string $format): bool
    {
        return $format === 'pair'
            ? $p->works_pair && $p->price_pair !== null
            : $p->works_individual && $p->price_individual !== null;
    }
}
