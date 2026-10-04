<?php

namespace Tests\Feature\Promo;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Promo\Contracts\PromoCodes;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Models\ReferralInvite;
use App\Modules\Promo\Models\ReferralLink;
use App\Modules\Promo\Services\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPsychologists;
use Tests\Concerns\PayoutFixtures;
use Tests\TestCase;

/** "Пригласи друга" (CL-13, DEC-42, SEQ-19, BR-PROMO-11). */
class ReferralTest extends TestCase
{
    use CreatesPsychologists, PayoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Moscow'));
    }

    private function inviter(string $email = 'inviter@example.com'): User
    {
        $user = User::factory()->withRole('client')->create(['email' => $email]);

        return $user->fresh();
    }

    private function register(string $email, ?string $code, string $role = 'client'): User
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => $email, 'password' => 'secret123', 'password_confirmation' => 'secret123', 'name' => 'Мария', 'last_name' => 'Петрова',
            'birth_date' => '1990-05-05', 'accept_personal_data' => true, 'accept_terms' => true, 'role' => $role, 'referral_code' => $code,
        ])->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    private function pay(TherapySession $session): void
    {
        $session->transitionTo(TherapySession::PAID, null, 'charged', ['paid_at' => now(), 'payment_source' => 'card']);
    }

    public function test_client_gets_a_stable_personal_invite_link(): void
    {
        $inviter = $this->inviter();
        Sanctum::actingAs($inviter);

        $first = $this->getJson('/api/v1/client/invite')->assertOk()
            ->assertJsonPath('data.terms.friend_discount_percent', 50)
            ->assertJsonPath('data.terms.reward_type', 'fixed')
            ->assertJsonPath('data.terms.reward_value', 100000)
            ->assertJsonPath('data.invites', []);
        $code = $first->json('data.code');
        $this->assertStringEndsWith('/auth/register?ref='.$code, $first->json('data.url'));
        $this->getJson('/api/v1/client/invite')->assertOk()->assertJsonPath('data.code', $code);

        $this->actingAsRole('psychologist');
        $this->getJson('/api/v1/client/invite')->assertForbidden();
    }

    public function test_friend_registers_gets_first_session_code_and_inviter_is_rewarded_after_first_paid_session(): void
    {
        $inviter = $this->inviter();
        $link = app(ReferralService::class)->linkFor($inviter);

        $friend = $this->register('friend@example.com', strtolower($link->code));
        $invite = ReferralInvite::sole();
        $this->assertSame('registered', $invite->status);
        $this->assertSame($friend->id, $invite->invitee_id);
        $friendCode = PromoCode::findOrFail($invite->promo_code_id);
        $this->assertSame('first_session', $friendCode->type);
        $this->assertSame(50, (int) $friendCode->value);
        $this->assertSame('individual', $friendCode->kind);
        $this->assertSame('referral', $friendCode->source);
        $this->assertSame($friend->id, $friendCode->owner_user_id);
        $this->assertSame('active', $friendCode->status);
        $this->assertSame(1, (int) $friendCode->total_limit);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $friend->id, 'template_code' => 'promo.referral_friend_code']);

        // Only the friend can use it, for the first paid session.
        $psy = $this->makePsychologist(['price_individual' => 400000]);
        $promo = app(PromoCodes::class);
        try {
            $promo->quote($friendCode->code, $inviter, $psy, 'individual', 400000);
            $this->fail('Foreign referral code accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('выдан другому', $e->errors()['promo_code'][0]);
        }
        $this->assertSame(200000, $promo->quote($friendCode->code, $friend, $psy, 'individual', 400000)['discount']);

        $session = $this->makeSession($psy, $friend, CarbonImmutable::now()->addDays(2), TherapySession::BOOKED, ['discount' => 200000, 'amount_due' => 200000, 'promo_code_id' => $friendCode->id]);
        $promo->reserve($friendCode->code, $friend, $session);
        $this->pay($session);

        $invite->refresh();
        $this->assertSame('rewarded', $invite->status);
        $this->assertSame($session->id, $invite->rewarded_session_id);
        $reward = PromoCode::findOrFail($invite->reward_code_id);
        $this->assertSame('fixed', $reward->type);
        $this->assertSame(100000, (int) $reward->value);
        $this->assertSame($inviter->id, $reward->owner_user_id);
        $this->assertSame('active', $reward->status);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $inviter->id, 'template_code' => 'promo.referral_reward']);

        // A reschedule after the charge emits book.session.paid again: no second reward.
        $session->fresh()->transitionTo(TherapySession::PAID, null, 'rescheduled after charge');
        $this->assertSame(1, PromoCode::where('owner_user_id', $inviter->id)->count());

        Sanctum::actingAs($inviter);
        $this->getJson('/api/v1/client/invite')->assertOk()
            ->assertJsonPath('data.stats.rewarded', 1)
            ->assertJsonPath('data.invites.0.status', 'rewarded')
            ->assertJsonPath('data.invites.0.friend', 'Мария П.')
            ->assertJsonPath('data.rewards.0.code', $reward->code)
            ->assertJsonPath('data.rewards.0.discount_label', '−1 000 ₽');
        Sanctum::actingAs($friend);
        $this->getJson('/api/v1/client/invite')->assertOk()->assertJsonPath('data.friend_code.code', $friendCode->code);
    }

    public function test_self_invite_by_email_variant_is_rejected(): void
    {
        $inviter = $this->inviter('anna.k@gmail.com');
        $link = app(ReferralService::class)->linkFor($inviter);

        $this->register('annak+friend@gmail.com', $link->code);
        $invite = ReferralInvite::sole();
        $this->assertSame('rejected', $invite->status);
        $this->assertSame('self_invite', $invite->rejected_reason);
        $this->assertNull($invite->promo_code_id);
        $this->assertSame(0, PromoCode::count());
    }

    public function test_same_card_blocks_the_reward(): void
    {
        $inviter = $this->inviter();
        $link = app(ReferralService::class)->linkFor($inviter);
        $friend = $this->register('friend2@example.com', $link->code);
        foreach ([$inviter, $friend] as $owner) {
            PaymentMethod::create(['user_id' => $owner->id, 'gateway' => 'fake', 'token' => 'tok_shared_card', 'purpose' => 'payment', 'card_mask' => '4111 11** **** 1111', 'exp_month' => 1, 'exp_year' => 2030, 'status' => 'active']);
        }

        $this->pay($this->makeSession($this->makePsychologist(), $friend, CarbonImmutable::now()->addDay(), TherapySession::BOOKED));

        $invite = ReferralInvite::sole();
        $this->assertSame('rejected', $invite->status);
        $this->assertSame('same_card', $invite->rejected_reason);
        $this->assertSame(0, PromoCode::where('owner_user_id', $inviter->id)->count());
    }

    public function test_corporate_first_session_does_not_count(): void
    {
        $inviter = $this->inviter();
        $friend = $this->register('friend3@example.com', app(ReferralService::class)->linkFor($inviter)->code);
        $psy = $this->makePsychologist();

        $corporate = $this->makeSession($psy, $friend, CarbonImmutable::now()->addDay(), TherapySession::BOOKED, ['corporate_participation_id' => $this->corporateParticipation($friend)->id]);
        $this->pay($corporate);
        $this->assertSame('registered', ReferralInvite::sole()->status);

        $this->pay($this->makeSession($psy, $friend, CarbonImmutable::now()->addDays(3), TherapySession::BOOKED));
        $this->assertSame('rewarded', ReferralInvite::sole()->status);
    }

    public function test_psychologist_registration_unknown_code_and_duplicates(): void
    {
        $inviter = $this->inviter();
        $link = app(ReferralService::class)->linkFor($inviter);

        $this->register('psy@example.com', $link->code, 'psychologist');
        $this->assertSame('not_client', ReferralInvite::sole()->rejected_reason);

        $this->register('nobody@example.com', 'UNKNOWN1');
        $this->assertSame(1, ReferralInvite::count());

        $friend = $this->register('friend4@example.com', $link->code);
        $this->assertNull(app(ReferralService::class)->onRegistered($friend, $link->code));
        $this->assertSame(1, ReferralInvite::where('invitee_id', $friend->id)->count());
        $this->assertSame(1, ReferralLink::count());
    }

    public function test_email_normalization(): void
    {
        $this->assertTrue(ReferralService::sameEmail('Anna.K@Gmail.com', 'annak+promo@googlemail.com'));
        $this->assertTrue(ReferralService::sameEmail('user+1@yandex.ru', 'USER@yandex.ru'));
        $this->assertFalse(ReferralService::sameEmail('user.name@yandex.ru', 'username@yandex.ru'));
    }
}
