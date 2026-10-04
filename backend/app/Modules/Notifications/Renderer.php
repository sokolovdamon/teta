<?php

namespace App\Modules\Notifications;

class Renderer
{
    /** Replace {{var}} placeholders; unknown variables become empty strings. */
    public static function render(string $template, array $vars, bool $escape = false): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($vars, $escape) {
            $value = data_get($vars, $m[1], '');
            $value = is_scalar($value) ? (string) $value : '';

            return $escape ? e($value) : $value;
        }, $template) ?? $template;
    }
}
