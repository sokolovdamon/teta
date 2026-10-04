<?php

namespace App\Modules\Instance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings of THIS instance (DEC-52: one instance — one database; partners get a separate copy).
 * Branding (name, logo, palette, domain), sender, requisites and integration settings (ADM-21, ADM-26).
 */
class InstanceConfig
{
    private const CACHE_KEY = 'instance_settings';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'name' => 'ТЕТА',
            'legal_name' => 'ИП Иващенко',
            'requisites' => ['inn' => null, 'ogrnip' => null, 'address' => null, 'bank' => null],
            'domain' => 'teta.su',
            'site_url' => config('app.frontend_url'),
            'logo_url' => null,
            'logo_dark_url' => null,
            'palette' => ['brand' => '#4D427A', 'graphite' => '#4A4A4A'],
            'support_email' => 'support@teta.su',
            'mail_from_address' => config('mail.from.address'),
            'mail_from_name' => config('mail.from.name'),
            'metrika_counter_id' => null,
            'dzen_enabled' => false,
            'is_partner_instance' => false,
            'emergency_phone' => '112',
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 300, fn () => DB::table('instance_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all());

        return array_replace(self::defaults(), $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('instance_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** Safe subset for the public site and letters. */
    public function publicConfig(): array
    {
        $all = $this->all();

        return collect($all)->only([
            'name', 'legal_name', 'domain', 'site_url', 'logo_url', 'logo_dark_url', 'palette', 'support_email', 'metrika_counter_id', 'emergency_phone',
        ])->all();
    }
}
