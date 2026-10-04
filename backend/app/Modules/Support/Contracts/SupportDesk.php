<?php

namespace App\Modules\Support\Contracts;

use App\Models\User;

/**
 * SUPPORT entry points for other modules. The bot German hands a dialog over to a live admin (DEC-14, DEC-54,
 * SEQ-14): the transcript becomes the first messages of a ticket marked as coming from the bot.
 *
 * @phpstan-type TranscriptLine array{role: 'user'|'bot', text: string, at?: string}
 */
interface SupportDesk
{
    /**
     * @param  list<array{role: string, text: string, at?: string}>  $transcript
     * @return string ticket id
     */
    public function openFromBotHandoff(User $user, string $subject, array $transcript, ?string $reason = null): string;

    /** @return string ticket id */
    public function openForUser(User $user, string $subject, string $message, ?string $category = null): string;
}
