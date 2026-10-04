<?php

namespace App\Modules\Support\Contracts;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Placeholder until the SUPPORT module binds its implementation: records nothing but a log line. */
class LoggingSupportDesk implements SupportDesk
{
    public function openFromBotHandoff(User $user, string $subject, array $transcript, ?string $reason = null): string
    {
        Log::info('Support desk is not installed: bot handoff dropped', ['user_id' => $user->id, 'reason' => $reason]);

        return (string) Str::uuid();
    }

    public function openForUser(User $user, string $subject, string $message, ?string $category = null): string
    {
        Log::info('Support desk is not installed: ticket dropped', ['user_id' => $user->id]);

        return (string) Str::uuid();
    }
}
