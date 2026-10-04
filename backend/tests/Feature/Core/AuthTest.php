<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Auth\Services\EmailLinkSigner;
use App\Modules\Consent\Models\Consent;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return [
            'email' => 'Client@Example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'name' => 'Анна',
            'birth_date' => now()->subYears(25)->toDateString(),
            'accept_personal_data' => true,
            'accept_terms' => true,
            ...$overrides,
        ];
    }

    public function test_client_registers_with_consents_and_gets_verification_letter(): void
    {
        Mail::fake();
        $res = $this->postJson('/api/v1/auth/register', $this->payload(['marketing_opt_in' => true]))->assertCreated();

        $res->assertJsonPath('user.email', 'client@example.com')
            ->assertJsonPath('user.roles', ['client'])
            ->assertJsonPath('user.email_verified', false);
        $this->assertNotEmpty($res->json('token'));

        $user = User::where('email', 'client@example.com')->first();
        $this->assertEqualsCanonicalizing(['personal_data', 'terms', 'mailing'], Consent::where('user_id', $user->id)->pluck('purpose')->all());
        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('client@example.com') && str_contains($m->subjectLine, 'Подтвердите email'));
        $this->assertDatabaseHas('domain_events', ['name' => 'auth.user.registered', 'aggregate_id' => $user->id]);
    }

    public function test_registration_requires_adult_age_and_personal_data_consent(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'birth_date' => now()->subYears(17)->toDateString(),
            'accept_personal_data' => false,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['birth_date', 'accept_personal_data']);
    }

    public function test_psychologist_registration_creates_draft_profile(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'psy@example.com', 'role' => 'psychologist', 'last_name' => 'Иванова']))
            ->assertCreated()->assertJsonPath('user.roles', ['psychologist']);

        $psy = Psychologist::whereHas('user', fn ($q) => $q->where('email', 'psy@example.com'))->firstOrFail();
        $this->assertSame('draft', $psy->qualification_status);
        $this->assertFalse($psy->isBookable());
    }

    public function test_email_verification_link_confirms_address(): void
    {
        $user = User::factory()->unverified()->withRole('client')->create();
        $link = app(EmailLinkSigner::class)->make($user, $user->email);

        $this->postJson('/api/v1/auth/email/verify', $link)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/v1/auth/email/verify', [...$link, 'sig' => 'forged'])->assertUnprocessable();
    }

    public function test_login_locks_after_repeated_failures(): void
    {
        $user = User::factory()->withRole('client')->create(['email' => 'lock@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'lock@example.com', 'password' => 'wrong-pass1']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'lock@example.com', 'password' => 'password1'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'Слишком много попыток'));
        $this->assertNotNull($user->fresh()->locked_until);
    }

    public function test_login_and_me(): void
    {
        User::factory()->withRole('client')->create(['email' => 'me@example.com']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ME@example.com', 'password' => 'password1'])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'me@example.com');
    }

    public function test_blocked_user_cannot_login(): void
    {
        $user = User::factory()->withRole('client')->create(['email' => 'blocked@example.com']);
        $user->forceFill(['status' => User::STATUS_BLOCKED])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'blocked@example.com', 'password' => 'password1'])->assertUnprocessable();
    }

    public function test_password_reset_flow(): void
    {
        Mail::fake();
        $user = User::factory()->withRole('client')->create(['email' => 'reset@example.com']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        Mail::assertSent(TemplatedMail::class, 1);

        $token = Password::broker()->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com', 'token' => $token, 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->postJson('/api/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'newpass123'])->assertOk();
    }

    public function test_email_change_requires_confirmation_of_new_address(): void
    {
        Mail::fake();
        $user = $this->actingAsRole('client');
        $this->postJson('/api/v1/account/email', ['email' => 'new@example.com', 'password' => 'password1'])->assertOk();
        $this->assertSame('new@example.com', $user->fresh()->pending_email);

        $link = app(EmailLinkSigner::class)->make($user, 'new@example.com');
        $this->postJson('/api/v1/auth/email/verify', $link)->assertOk();
        $this->assertSame('new@example.com', $user->fresh()->email);
    }
}
