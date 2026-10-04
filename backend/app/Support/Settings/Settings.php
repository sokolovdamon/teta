<?php

namespace App\Support\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rule parameters P-* with admin overrides. Read with Settings::get('P-COMMISSION').
 * Values that were in force at an event are copied into the entity (sequences_states.md, rule 5).
 */
class Settings
{
    private const CACHE_KEY = 'platform_settings.overrides';

    public static function get(string $key): mixed
    {
        $definitions = config('platform.parameters');
        if (! array_key_exists($key, $definitions)) {
            throw new InvalidArgumentException("Unknown platform parameter {$key}");
        }
        $overrides = self::overrides();

        return array_key_exists($key, $overrides) ? $overrides[$key] : $definitions[$key]['value'];
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** @return array<string, array{value: mixed, default: mixed, unit: string, group: string, title: string, overridden: bool}> */
    public static function all(): array
    {
        $overrides = self::overrides();
        $out = [];
        foreach (config('platform.parameters') as $key => $def) {
            $out[$key] = [
                'value' => array_key_exists($key, $overrides) ? $overrides[$key] : $def['value'],
                'default' => $def['value'],
                'unit' => $def['unit'],
                'group' => $def['group'],
                'title' => $def['title'],
                'overridden' => array_key_exists($key, $overrides),
            ];
        }

        return $out;
    }

    public static function set(string $key, mixed $value, ?string $userId = null): void
    {
        if (! array_key_exists($key, config('platform.parameters'))) {
            throw new InvalidArgumentException("Unknown platform parameter {$key}");
        }
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()],
        );
        self::flush();
    }

    public static function reset(string $key): void
    {
        DB::table('platform_settings')->where('key', $key)->delete();
        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private static function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, fn () => DB::table('platform_settings')
            ->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true))
            ->all());
    }
}
