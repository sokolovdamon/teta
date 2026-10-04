<?php

namespace App\Modules\Notifications;

/**
 * Default notification templates. Each module may ship app/Modules/<Module>/notifications.php returning
 * [code => [title, audience, subject, body, center_text?, link?, send_email?, send_center?, is_transactional?]].
 * Templates are copied into the DB by the seeder (only missing ones) and edited by admins in ADM-10.
 * Variables are written as {{name}}. Letters never contain "сведения о состоянии" (BR-NOTIF-04).
 */
class NotificationCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $templates = [];
        foreach (glob(app_path('Modules/*/notifications.php')) ?: [] as $file) {
            $templates = array_merge($templates, require $file);
        }

        return $templates;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }
}
