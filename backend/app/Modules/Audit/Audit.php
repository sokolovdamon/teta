<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Journal of user actions (ADM-25): Audit::log('ADM-02', 'user.blocked', $user, ['reason' => ...]).
 * Never put "сведения о состоянии" (diary entries, requests, notes) into changes.
 */
class Audit
{
    /** @param  array<string, mixed>|null  $changes */
    public static function log(string $section, string $action, ?Model $subject = null, ?array $changes = null, ?string $comment = null, ?string $userId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'section' => $section,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'comment' => $comment,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}
