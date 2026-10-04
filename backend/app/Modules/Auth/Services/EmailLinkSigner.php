<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Support\Settings\Settings;

/**
 * Stateless signed links for email confirmation and email change: HMAC over user, target email and expiry.
 * Lifetime is P-EMAIL-LINK-TTL. A link stops working once the email it confirms is changed.
 */
class EmailLinkSigner
{
    /** @return array{uid: string, email: string, exp: int, sig: string} */
    public function make(User $user, string $email): array
    {
        $exp = now()->addMinutes(Settings::int('P-EMAIL-LINK-TTL'))->getTimestamp();

        return ['uid' => $user->id, 'email' => $email, 'exp' => $exp, 'sig' => $this->sign($user->id, $email, $exp)];
    }

    public function url(User $user, string $email, string $path = '/auth/verify'): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path.'?'.http_build_query($this->make($user, $email));
    }

    public function valid(string $uid, string $email, int $exp, string $sig): bool
    {
        return $exp >= now()->getTimestamp() && hash_equals($this->sign($uid, $email, $exp), $sig);
    }

    private function sign(string $uid, string $email, int $exp): string
    {
        return hash_hmac('sha256', "{$uid}|".mb_strtolower($email)."|{$exp}", (string) config('app.key'));
    }
}
