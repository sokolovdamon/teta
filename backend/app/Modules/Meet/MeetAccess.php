<?php

namespace App\Modules\Meet;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Who may enter a TetaMeet room and when. Each module that holds meetings registers a resolver for its type:
 *
 *   MeetAccess::register('supervision', fn (string $id, User $user) => [
 *       'role' => 'host' | 'participant' | 'viewer',  // null when the user has no access
 *       'starts_at' => CarbonImmutable, 'ends_at' => CarbonImmutable,
 *       'title' => 'Групповая супервизия', 'meetable' => $model,
 *   ]);
 *
 * Types: session (TherapySession, MEET registers it), supervision, intervision, event.
 * The MEET module opens rooms P-ROOM-OPEN before starts_at and closes them P-ROOM-CLOSE after ends_at.
 */
class MeetAccess
{
    /** @var array<string, callable(string, User): ?array{role: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, title?: string, meetable?: mixed}> */
    private static array $resolvers = [];

    public static function register(string $type, callable $resolver): void
    {
        self::$resolvers[$type] = $resolver;
    }

    public static function has(string $type): bool
    {
        return isset(self::$resolvers[$type]);
    }

    /** @return array{role: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, title?: string, meetable?: mixed}|null */
    public static function resolve(string $type, string $id, User $user): ?array
    {
        $resolver = self::$resolvers[$type] ?? null;
        if (! $resolver) {
            return null;
        }
        $access = $resolver($id, $user);

        return $access && ($access['role'] ?? null) ? $access : null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::$resolvers);
    }
}
