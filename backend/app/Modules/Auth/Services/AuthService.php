<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Consent\Models\LegalDocument;
use App\Modules\Consent\Services\ConsentService;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Notifier;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Rbac\Services\RbacService;
use App\Support\Events\Outbox;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private RbacService $rbac,
        private ConsentService $consents,
        private Notifier $notifier,
        private EmailLinkSigner $links,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'],
                'password' => $data['password'],
                'name' => $data['name'],
                'last_name' => $data['last_name'] ?? null,
                'birth_date' => $data['birth_date'],
                'timezone' => $data['timezone'] ?? config('platform.timezone'),
            ]);
            $role = $data['role'] ?? 'client';
            $this->rbac->assignRole($user, $role);

            $this->consents->accept($user, LegalDocument::PERSONAL_DATA, field: 'accept_personal_data');
            $this->consents->accept($user, LegalDocument::TERMS, field: 'accept_terms');
            $marketing = (bool) ($data['marketing_opt_in'] ?? false);
            if ($marketing) {
                $this->consents->accept($user, LegalDocument::MAILING);
            }
            NotificationPreference::create(['user_id' => $user->id, 'marketing_emails' => $marketing]);

            if ($role === 'psychologist') {
                // ST-08: a psychologist starts as a draft; "кандидат" is a qualification status, not a role.
                $psychologist = new Psychologist([
                    'user_id' => $user->id,
                    'slug' => Psychologist::uniqueSlug($user->name.' '.($user->last_name ?? '')),
                    'first_name' => $user->name,
                    'last_name' => $user->last_name ?? '',
                    'timezone' => $user->timezone,
                ]);
                $psychologist->forceFill(['qualification_status' => 'draft'])->save();
            }

            Outbox::record('auth.user.registered', $user, ['role' => $role, 'referral_code' => $data['referral_code'] ?? null], $user->id);
            $this->sendVerification($user);

            return $user;
        });
    }

    public function sendVerification(User $user): void
    {
        $this->notifier->send($user, 'auth.verify_email', [], $this->links->url($user, $user->email), 'Подтвердить email');
    }

    /** P-LOGIN-ATTEMPTS failures lock the account for P-LOGIN-LOCK minutes. */
    public function attempt(string $email, string $password): User
    {
        $user = User::where('email', mb_strtolower(trim($email)))->first();
        $fail = fn (string $message = 'Неверный email или пароль.') => throw ValidationException::withMessages(['email' => $message]);

        if (! $user) {
            Hash::check($password, '$2y$12$'.str_repeat('a', 53));
            $fail();
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            $minutes = (int) ceil(now()->diffInSeconds($user->locked_until) / 60);
            $fail("Слишком много попыток входа. Попробуйте через {$minutes} мин. или восстановите пароль.");
        }
        if (! Hash::check($password, $user->password)) {
            $failed = $user->failed_logins + 1;
            $lock = $failed >= Settings::int('P-LOGIN-ATTEMPTS');
            $user->forceFill(['failed_logins' => $lock ? 0 : $failed, 'locked_until' => $lock ? now()->addMinutes(Settings::int('P-LOGIN-LOCK')) : null])->save();
            if ($lock) {
                Audit::log('X-01', 'auth.locked', $user, null, 'too many failed logins', $user->id);
            }
            $fail();
        }
        if ($user->status === User::STATUS_BLOCKED) {
            $fail('Аккаунт заблокирован. Напишите в поддержку: '.config('mail.from.address'));
        }
        if ($user->status === User::STATUS_DELETED) {
            $fail();
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        return $user;
    }

    public function issueToken(User $user, string $device = 'web'): string
    {
        return $user->createToken($device.':'.Str::limit((string) request()->userAgent(), 60, ''))->plainTextToken;
    }

    public function verifyEmail(string $uid, string $email, int $exp, string $sig): User
    {
        if (! $this->links->valid($uid, $email, $exp, $sig)) {
            throw ValidationException::withMessages(['link' => 'Ссылка недействительна или устарела. Запросите новое письмо.']);
        }
        $user = User::findOrFail($uid);
        $email = mb_strtolower($email);

        if ($user->email === $email) {
            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
                Outbox::record('auth.email.verified', $user, [], $user->id);
            }
        } elseif ($user->pending_email === $email) {
            if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'Этот email уже занят.']);
            }
            $old = $user->email;
            $user->forceFill(['email' => $email, 'pending_email' => null, 'email_verified_at' => now()])->save();
            Audit::log('CL-08', 'account.email_changed', $user, ['from' => $old, 'to' => $email], userId: $user->id);
        } else {
            throw ValidationException::withMessages(['link' => 'Ссылка относится к другому адресу.']);
        }

        return $user;
    }

    public function requestEmailChange(User $user, string $newEmail): void
    {
        $user->forceFill(['pending_email' => $newEmail])->save();
        $this->notifier->sendToEmail($newEmail, 'auth.email_change', ['name' => $user->name], $this->links->url($user, $newEmail), 'Подтвердить новый email');
    }
}
