<?php

namespace Tests\Feature\Booking;

use App\Models\User;
use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Models\BookingIntent;
use App\Modules\Booking\Models\SessionTimeRequest;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Corporate\Models\Company;
use App\Modules\Corporate\Models\CorporateParticipation;
use App\Modules\Corporate\Models\CorporateProgram;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Payments\Gateway\Emulator\EmulatorCheckout;
use App\Modules\Payments\Gateway\Emulator\EmulatorOperation;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\Receipt;
use App\Modules\Promo\Contracts\PromoCodes;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Models\SlotHold;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use BookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    public function test_quote_returns_payment_mode_charge_time_and_requirements(): void
    {
        $p = $this->makePsychologist(['price_individual' => 350000]);
        $client = $this->actAs($this->client());

        $deferred = $this->getJson('/api/v1/booking/quote?'.http_build_query(['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-07 12:00')->toIso8601String()]))->assertOk();
        $deferred->assertJsonPath('data.payment_mode', 'deferred')
            ->assertJsonPath('data.price', 350000)
            ->assertJsonPath('data.amount_due', 350000)
            ->assertJsonPath('data.charge_at', $this->msk('2026-10-07 00:00')->utc()->toIso8601String())
            ->assertJsonPath('data.requirements.card_required', true)
            ->assertJsonPath('data.requirements.card_bound', false);

        $this->getJson('/api/v1/booking/quote?'.http_build_query(['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-05 15:00')->toIso8601String()]))
            ->assertOk()->assertJsonPath('data.payment_mode', 'immediate')->assertJsonPath('data.charge_at', null);

        // Guest quote works without promo code.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/booking/quote?'.http_build_query(['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-07 12:00')->toIso8601String(), 'format' => 'pair']))
            ->assertStatus(422)->assertJsonValidationErrors('format');
        $this->assertNotNull($client);
    }

    public function test_deferred_booking_requires_card_then_creates_booked_session_with_charge_task_and_letters(): void
    {
        Mail::fake();
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $client = $this->actAs($this->client(['timezone' => 'Asia/Yekaterinburg']));
        $payload = ['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-08 14:00')->toIso8601String(), 'client_request_ids' => []];

        $this->postJson('/api/v1/booking/sessions', $payload)->assertStatus(422)->assertJsonPath('code', 'card_required');

        $this->bindCard($client);
        $res = $this->postJson('/api/v1/booking/sessions', $payload)->assertCreated();
        $res->assertJsonPath('data.status', 'booked')->assertJsonPath('data.is_charged', false)
            ->assertJsonPath('data.free_cancel_until', $this->msk('2026-10-08 02:00')->utc()->toIso8601String());

        $session = TherapySession::firstOrFail();
        $this->assertSame(400000, (int) $session->price);
        $this->assertSame('Asia/Yekaterinburg', $session->client_timezone);
        $this->assertSame(720, $session->params['P-CHARGE-OFFSET']);
        $task = ChargeTask::where('therapy_session_id', $session->id)->firstOrFail();
        $this->assertSame('scheduled', $task->status);
        $this->assertTrue($task->due_at->equalTo($this->msk('2026-10-08 02:00')));
        $this->assertTrue($task->deadline_at->equalTo($this->msk('2026-10-08 12:00')));
        $this->assertCount(1, $this->events('book.session.booked'));
        $this->assertSame('booked', $this->events('book.session.booked')->first()->payload['to']);

        // DEC-16: day and time in the client's timezone, link to the cabinet; PRO-10: psychologist letter.
        Mail::assertSent(TemplatedMail::class, fn ($m) => str_contains($m->subjectLine, 'Запись подтверждена') && str_contains($m->bodyHtml, '16:00 (UTC+05:00)') && str_contains((string) $m->actionUrl, '/client/sessions'));
        Mail::assertSent(TemplatedMail::class, fn ($m) => str_contains($m->subjectLine, 'Новая запись') && str_contains($m->bodyHtml, '14:00 (МСК)'));

        // Price is fixed at booking (BR-BOOK-03).
        Psychologist::whereKey($p->id)->update(['price_individual' => 600000]);
        $this->assertSame(400000, (int) $session->fresh()->price);
    }

    public function test_requests_are_visible_only_to_the_psychologist_and_never_in_letters(): void
    {
        Mail::fake();
        $p = $this->makePsychologist();
        $client = $this->client();
        $this->bindCard($client);
        $request = ClientRequest::where('slug', 'panicheskie-ataki')->firstOrFail();
        $session = $this->book($client, $p, '2026-10-08 14:00', ['client_request_ids' => [$request->id]]);

        $this->actAs($client);
        $this->getJson("/api/v1/booking/sessions/{$session->id}")->assertOk()->assertJsonMissingPath('data.client_requests');
        $this->actAs(User::findOrFail($p->user_id));
        $this->getJson("/api/v1/booking/pro/sessions/{$session->id}")->assertOk()->assertJsonPath('data.client_requests.0', 'Панические атаки');
        Mail::assertNotSent(TemplatedMail::class, fn ($m) => str_contains($m->bodyHtml, 'Панические атаки'));
    }

    public function test_immediate_booking_spends_balance_first_then_charges_saved_card(): void
    {
        $p = $this->makePsychologist(['price_individual' => 350000]);
        $client = $this->actAs($this->client());
        $this->bindCard($client);
        $this->credit($client, 100000);

        $res = $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-05 15:00')->toIso8601String()])->assertCreated();
        $res->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_source', 'mixed');

        $session = TherapySession::firstOrFail();
        $this->assertSame(350000, (int) $session->amount_charged);
        $this->assertSame(100000, (int) $session->paid_balance);
        $this->assertSame(250000, (int) $session->paid_card);
        $this->assertSame(250000, (int) Payment::findOrFail($session->payment_id)->amount);
        $this->assertSame('PREPAYMENT_FULL', Receipt::where('payment_id', $session->payment_id)->value('calculation_method'));
        $this->assertSame(0, $this->balanceOf($client)['available']);
        $this->assertNull(ChargeTask::first());
        $this->assertSame(0, SlotHold::count());
    }

    public function test_immediate_booking_fully_from_balance(): void
    {
        $p = $this->makePsychologist(['price_individual' => 300000]);
        $client = $this->client();
        $this->credit($client, 500000);
        $session = $this->book($client, $p, '2026-10-05 16:00');
        $this->assertSame('paid', $session->status);
        $this->assertSame('balance', $session->payment_source);
        $this->assertSame(200000, $this->balanceOf($client)['available']);
        $this->assertSame(0, Payment::count());
    }

    public function test_declined_immediate_payment_does_not_create_booking_and_reverses_balance(): void
    {
        $p = $this->makePsychologist(['price_individual' => 350000]);
        $client = $this->actAs($this->client());
        $this->bindCard($client, '4000 0000 0000 9995');
        $this->credit($client, 50000);

        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-05 15:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'payment_declined');

        $this->assertSame(0, TherapySession::count());
        $this->assertSame(BookingIntent::FAILED, BookingIntent::firstOrFail()->status);
        $this->assertSame('spend_reversed', ClientBalanceOperation::where('type', 'spend')->value('status'));
        $this->assertSame(50000, $this->balanceOf($client)['available']);
        // The slot stays held for the client to pay with another card.
        $this->assertSame(1, SlotHold::where('user_id', $client->id)->count());
    }

    public function test_immediate_booking_without_card_goes_through_checkout_and_3ds(): void
    {
        $p = $this->makePsychologist(['price_individual' => 350000]);
        $client = $this->actAs($this->client());
        $other = $this->client();

        $res = $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-05 15:00')->toIso8601String(), 'idempotency_key' => 'k1'])
            ->assertStatus(202);
        $url = $res->json('confirmation_url');
        $intentId = $res->json('intent.id');
        $this->assertNotNull($url);
        $this->assertSame(0, TherapySession::count());

        // While the payer pays, the slot is held for them.
        try {
            app(BookingService::class)->hold($other, $p, 'individual', $this->msk('2026-10-05 15:00'));
            $this->fail('Slot must be held');
        } catch (ValidationException) {
        }

        $op = EmulatorOperation::findOrFail(basename($url));
        $op = app(EmulatorCheckout::class)->submitCard($op, ['card_number' => '4000000000003220', 'exp_month' => 5, 'exp_year' => 2031, 'cvc' => '321']);
        $this->assertSame('awaiting_3ds', $op->status);
        app(EmulatorCheckout::class)->confirm3ds($op, true);

        $intent = BookingIntent::findOrFail($intentId);
        $this->assertSame(BookingIntent::COMPLETED, $intent->status);
        $session = TherapySession::findOrFail($intent->therapy_session_id);
        $this->assertSame('paid', $session->status);
        $this->assertSame('card', $session->payment_source);
        $this->getJson("/api/v1/booking/intents/{$intentId}")->assertOk()->assertJsonPath('session.id', $session->id);
        // The card used in the checkout is saved for future charges.
        $this->assertSame(1, PaymentMethod::where('user_id', $client->id)->count());
        // Repeating the request with the same idempotency key returns the same booking.
        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-05 15:00')->toIso8601String(), 'idempotency_key' => 'k1'])
            ->assertCreated()->assertJsonPath('data.id', $session->id);
        $this->assertSame(1, TherapySession::count());
    }

    public function test_limits_age_and_email_verification(): void
    {
        $p = $this->makePsychologist();
        $client = $this->actAs($this->client());
        $this->bindCard($client);
        foreach (['2026-10-08 12:00', '2026-10-09 12:00', '2026-10-10 12:00'] as $at) {
            $this->book($client, $p, $at);
        }
        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-11 12:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'max_upcoming');

        $other = $this->makePsychologist();
        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $other->id, 'starts_at' => $this->msk('2026-10-08 12:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'client_overlap');

        $minor = $this->actAs($this->client(['birth_date' => '2010-01-01']));
        $this->bindCard($minor);
        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $other->id, 'starts_at' => $this->msk('2026-10-12 12:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'not_adult');

        $this->actAs(User::factory()->unverified()->withRole('client')->create());
        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $other->id, 'starts_at' => $this->msk('2026-10-12 12:00')->toIso8601String()])
            ->assertStatus(403);
    }

    public function test_hold_blocks_slot_for_others_and_is_adopted_at_booking(): void
    {
        $p = $this->makePsychologist();
        $start = $this->msk('2026-10-08 14:00')->toIso8601String();
        $hold = $this->postJson('/api/v1/booking/holds', ['psychologist_id' => $p->id, 'starts_at' => $start])->assertCreated();
        $this->assertNotNull($hold->json('data.guest_token'));

        $client = $this->actAs($this->client());
        $this->bindCard($client);
        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $p->id, 'starts_at' => $start])->assertStatus(422);
        $this->postJson('/api/v1/booking/sessions', ['psychologist_id' => $p->id, 'starts_at' => $start, 'hold_id' => $hold->json('data.id'), 'guest_token' => $hold->json('data.guest_token')])
            ->assertCreated();
        $this->assertSame(0, SlotHold::count());
    }

    public function test_pair_session_partner_invitation_and_acceptance(): void
    {
        Mail::fake();
        $p = $this->makePsychologist(['price_individual' => 400000, 'price_pair' => 600000]);
        $client = $this->client();
        $this->bindCard($client);
        $session = $this->book($client, $p, '2026-10-08 10:00', ['format' => 'pair', 'partner_email' => 'Partner@Example.com']);
        $this->assertSame(90, (int) $session->duration_min);
        $this->assertSame(600000, (int) $session->price);
        $this->assertSame('partner@example.com', $session->partner_email);
        Mail::assertSent(TemplatedMail::class, fn ($m) => str_contains($m->subjectLine, 'приглашает вас на парную сессию') && str_contains((string) $m->actionUrl, '/client/sessions?invite='.$session->id));
        $this->assertCount(1, $this->events('book.pair_invite.created'));

        $stranger = $this->actAs($this->client());
        $this->postJson("/api/v1/booking/sessions/{$session->id}/partner/accept")->assertForbidden();

        $partner = $this->actAs($this->client(['email' => 'partner@example.com']));
        $this->getJson('/api/v1/booking/sessions')->assertOk()->assertJsonPath('invitations.0.id', $session->id);
        $this->postJson("/api/v1/booking/sessions/{$session->id}/partner/accept")->assertOk()->assertJsonPath('data.role', 'partner');
        $this->assertSame($partner->id, $session->fresh()->partner_user_id);
        $this->getJson('/api/v1/booking/sessions')->assertOk()->assertJsonPath('data.0.id', $session->id)->assertJsonMissingPath('data.0.price');
        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel")->assertNotFound();
        $this->assertNotNull($stranger);
    }

    public function test_corporate_booking_is_paid_by_the_company(): void
    {
        $fake = new class implements CorporateCoverage
        {
            public array $consumed = [];

            public array $released = [];

            public ?CorporateParticipation $participation = null;

            public function coverFor(User $client, string $format): ?CorporateParticipation
            {
                return $this->participation;
            }

            public function consume(TherapySession $session): void
            {
                $this->consumed[] = $session->id;
            }

            public function release(TherapySession $session): void
            {
                $this->released[] = $session->id;
            }
        };
        $this->app->instance(CorporateCoverage::class, $fake);
        $client = $this->client();
        $company = new Company;
        $company->forceFill(['name' => 'Ромашка', 'status' => 'active'])->save();
        $program = new CorporateProgram;
        $program->forceFill(['company_id' => $company->id, 'code' => 'X1', 'title' => 'Поддержка', 'sessions_limit' => 4, 'status' => 'active'])->save();
        $part = new CorporateParticipation;
        $part->forceFill(['corporate_program_id' => $program->id, 'user_id' => $client->id, 'work_email' => 'w@romashka.example', 'status' => 'active'])->save();
        $fake->participation = $part;

        $p = $this->makePsychologist();
        $session = $this->book($client, $p, '2026-10-08 14:00');
        $this->assertSame('paid', $session->status);
        $this->assertSame('corporate', $session->payment_source);
        $this->assertSame($part->id, $session->corporate_participation_id);
        $this->assertSame([$session->id], $fake->consumed);
        $this->assertNull(ChargeTask::first());

        // Cancel before the charge time keeps the limit (released back to the employee).
        $this->actAs($client);
        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel")->assertOk()->assertJsonPath('kind', 'free_cancel');
        $this->assertSame([$session->id], $fake->released);
    }

    public function test_promo_code_is_quoted_reserved_consumed_on_charge_and_restored_on_free_cancel(): void
    {
        $promo = new class implements PromoCodes
        {
            public array $log = [];

            public function quote(string $code, User $client, Psychologist $psychologist, string $format, int $price): array
            {
                if ($code !== 'HELLO') {
                    throw ValidationException::withMessages(['promo_code' => 'Промокод не найден.']);
                }

                return ['promo_code_id' => null, 'code' => 'HELLO', 'discount' => intdiv($price, 10)];
            }

            public function reserve(string $code, User $client, TherapySession $session): void
            {
                $this->log[] = 'reserve';
            }

            public function consume(TherapySession $session): void
            {
                $this->log[] = 'consume';
            }

            public function restore(TherapySession $session): void
            {
                $this->log[] = 'restore';
            }
        };
        $this->app->instance(PromoCodes::class, $promo);
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $client = $this->client();
        $this->bindCard($client);

        $this->actAs($client);
        $this->getJson('/api/v1/booking/quote?'.http_build_query(['psychologist_id' => $p->id, 'starts_at' => $this->msk('2026-10-08 14:00')->toIso8601String(), 'promo_code' => 'NOPE']))
            ->assertStatus(422)->assertJsonValidationErrors('promo_code');

        $session = $this->book($client, $p, '2026-10-08 14:00', ['promo_code' => 'HELLO']);
        $this->assertSame(40000, (int) $session->discount);
        $this->assertSame(360000, (int) $session->amount_due);
        $this->assertSame(360000, (int) ChargeTask::firstOrFail()->amount);
        $this->assertSame(['reserve'], $promo->log);

        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel")->assertOk();
        $this->assertSame(['reserve', 'restore'], $promo->log);
    }

    public function test_time_request_flow(): void
    {
        $p = $this->makePsychologist(intervals: [[3, '10:00', '12:00']]);
        $client = $this->actAs($this->client());
        $this->postJson('/api/v1/booking/time-requests', [
            'psychologist_id' => $p->id,
            'preferred' => [['weekday' => 2, 'from' => '19:00', 'to' => '21:00']],
            'comment' => 'После работы',
        ])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->postJson('/api/v1/booking/time-requests', ['psychologist_id' => $p->id, 'preferred' => [['weekday' => 2, 'from' => '19:00', 'to' => '21:00']]])
            ->assertStatus(422);
        $this->assertTrue(UserNotification::where('user_id', $p->user_id)->where('template_code', 'book.psy_time_request')->exists());

        $this->actAs(User::findOrFail($p->user_id));
        $id = $this->getJson('/api/v1/booking/pro/time-requests')->assertOk()->assertJsonPath('open', 1)->json('data.0.id');
        $this->assertContains($this->msk('2026-10-07 10:00')->utc()->toIso8601String(), $this->getJson('/api/v1/booking/pro/free-slots')->assertOk()->json('data'));
        $this->postJson("/api/v1/booking/pro/time-requests/{$id}/offer", ['slots' => [$this->msk('2026-10-06 19:00')->toIso8601String()]])
            ->assertStatus(422)->assertJsonPath('code', 'slot_unavailable');
        $this->postJson("/api/v1/booking/pro/time-requests/{$id}/offer", ['slots' => [$this->msk('2026-10-07 10:00')->toIso8601String()], 'comment' => 'Могу в среду утром'])
            ->assertOk()->assertJsonPath('data.status', 'offered');
        $this->assertTrue(UserNotification::where('user_id', $client->id)->where('template_code', 'book.time_request_offered')->exists());

        // Booking with this psychologist answers the request.
        $this->bindCard($client);
        $this->book($client, $p, '2026-10-07 10:00');
        $this->assertSame('booked', SessionTimeRequest::findOrFail($id)->status);
    }
}
