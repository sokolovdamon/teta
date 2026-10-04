<?php

namespace App\Modules\Consent\Services;

use App\Models\User;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\LegalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Fixes who accepted which document version, when and from where (X-03).
 * Consents to mailing, cookies and review publication are never a condition of the service (ЗоЗПП ст. 16).
 */
class ConsentService
{
    public function accept(?User $user, string $kind, ?Model $subject = null, ?string $email = null, ?string $field = null): Consent
    {
        $version = LegalDocument::currentVersionOfKind($kind);
        if (! $version) {
            throw ValidationException::withMessages([$field ?? 'consent' => "Документ «{$kind}» не опубликован. Обратитесь в поддержку."]);
        }
        $request = app()->runningInConsole() ? null : request();

        return Consent::create([
            'user_id' => $user?->id,
            'legal_document_version_id' => $version->id,
            'purpose' => $kind,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'email' => $email ?? $user?->email,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'accepted_at' => now(),
        ]);
    }

    public function revoke(User $user, string $kind): int
    {
        return Consent::where('user_id', $user->id)->where('purpose', $kind)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function has(User $user, string $kind): bool
    {
        return Consent::where('user_id', $user->id)->where('purpose', $kind)->whereNull('revoked_at')->exists();
    }
}
