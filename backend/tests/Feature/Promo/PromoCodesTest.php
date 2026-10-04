<?php

namespace Tests\Feature\Promo;

use App\Models\User;
use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Corporate\Models\CorporateParticipation;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Promo\Contracts\PromoCodes;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Models\PromoRedemption;
use App\Modules\Promo\Services\PromoAdminService;
use App\Modules\Promo\Services\PromoService;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesPsychologists;
use Tests\Concerns\PayoutFixtures;
use Tests\TestCase;

/** PROMO contract for BOOK: quote / reserve / consume / restore (Э8, ST-17, BR-PROMO-01…10). */
class PromoCodesTest extends TestCase
{
    use CreatesPsychologists, PayoutFixtures;

    private PromoCodes $promo;

    private Psychologist $psy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Moscow'));
        $this->promo = app(PromoCodes::class);
        $this->psy = $this->makePsychologist(['price_individual' => 400000, 'price_pair' => 600000]);
    }

    private function code(array $attrs = [], string $status = 'active'): PromoCode
    {
        $promo = new PromoCode([
            'code' => 'CODE'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'type' => 'percent', 'value' => 20, 'kind' => 'mass', 'per_user_limit' => 1,
            ...$attrs,
        ]);
        $promo->forceFill(['status' => $status])->save();

        return $promo;
    }

    private function bookedSession(User $client, array $attrs = []): TherapySession
    {
        return $this->makeSession($this->psy, $client, CarbonImmutable::now()->addDays(3), TherapySession::BOOKED, $attrs);
    }

    private function assertRejected(Closure $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected the promo code to be rejected with «{$message}»");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->errors()['promo_code'][0]);
        }
    }

    public function test_real_implementation_is_bound(): void
    {
        $this->assertInstanceOf(PromoService::class, $this->promo);
    }

    public function test_quote_returns_discount_by_type(): void
    {
        $client = $this->userWithRole('client');
        $percent = $this->code(['code' => 'AUTUMN20']);
        $quote = $this->promo->quote('  autumn20 ', $client, $this->psy, 'individual', 400000);
        $this->assertSame(['promo_code_id' => $percent->id, 'code' => 'AUTUMN20', 'discount' => 80000], $quote);

        $fixed = $this->code(['type' => 'fixed', 'value' => 50000]);
        $this->assertSame(50000, $this->promo->quote($fixed->code, $client, $this->psy, 'individual', 400000)['discount']);

        $huge = $this->code(['type' => 'fixed', 'value' => 900000]);
        $this->assertSame(400000, $this->promo->quote($huge->code, $client, $this->psy, 'individual', 400000)['discount']);

        $first = $this->code(['type' => 'first_session', 'value' => 50]);
        $this->assertSame(300000, $this->promo->quote($first->code, $client, $this->psy, 'pair', 600000)['discount']);
    }

    public function test_validation_matrix(): void
    {
        $client = $this->userWithRole('client');
        $paidClient = $this->userWithRole('client');
        $this->makeSession($this->psy, $paidClient, CarbonImmutable::now()->subDays(10), TherapySession::HELD, ['paid_at' => now()->subDays(11)]);
        $other = $this->userWithRole('client');
        $otherPsy = $this->makePsychologist();
        $premium = PriceCategory::where('code', 'premium')->firstOrFail();
        $quote = fn (string $code, ?User $who = null, string $format = 'individual', int $price = 400000) => fn () => $this->promo->quote($code, $who ?? $client, $this->psy, $format, $price);

        $this->assertRejected($quote('NOSUCHCODE'), 'Промокод не найден');
        $this->assertRejected($quote($this->code([], 'draft')->code), 'Промокод не действует');
        $this->assertRejected($quote($this->code([], 'deactivated')->code), 'Промокод не действует');
        $this->assertRejected($quote($this->code(['valid_from' => now()->addDays(5)], 'scheduled')->code), 'начнёт действовать');
        $this->assertRejected($quote($this->code(['valid_until' => now()->subMinute()])->code), 'Срок действия промокода истёк');
        $this->assertRejected($quote($this->code([], 'expired')->code), 'Срок действия промокода истёк');
        $this->assertRejected($quote($this->code(['total_limit' => 5, 'uses_count' => 5])->code), 'Лимит применений промокода исчерпан');
        $this->assertRejected($quote($this->code(['kind' => 'individual', 'owner_user_id' => $other->id])->code), 'выдан другому пользователю');
        $this->assertRejected($quote($this->code(['restrictions' => ['service_types' => ['pair']]])->code), 'только для парных');
        $this->assertRejected($quote($this->code(['restrictions' => ['service_types' => ['individual']]])->code, format: 'pair', price: 600000), 'не действует для парных');
        $this->assertRejected($quote($this->code(['restrictions' => ['psychologist_ids' => [$otherPsy->id]]])->code), 'выбранного психолога');
        $this->assertRejected($quote($this->code(['restrictions' => ['price_category_ids' => [$premium->id]]])->code), 'ценовой категории');
        $this->assertRejected($quote($this->code(['min_amount' => 500000])->code), 'от 5 000 ₽');
        $this->assertRejected($quote($this->code(['restrictions' => ['segment' => ['new_clients' => true]]])->code, $paidClient), 'только для новых клиентов');
        $this->assertRejected($quote($this->code(['restrictions' => ['segment' => ['registered_after' => '2026-12-01']]])->code), 'зарегистрированных с');
        $this->assertRejected($quote($this->code(['restrictions' => ['segment' => ['min_held_sessions' => 2]]])->code, $paidClient), 'не меньше 2 сессий');
        $this->assertRejected($quote($this->code(['type' => 'first_session', 'value' => 50])->code, $paidClient), 'первую оплаченную сессию');

        // The same restrictions pass when satisfied.
        $ok = $this->code([
            'owner_user_id' => $client->id, 'min_amount' => 300000,
            'restrictions' => ['service_types' => ['individual'], 'psychologist_ids' => [$this->psy->id], 'price_category_ids' => [$this->psy->price_category_id], 'segment' => ['new_clients' => true, 'registered_after' => '2026-01-01']],
        ]);
        $this->assertSame(80000, $this->promo->quote($ok->code, $client, $this->psy, 'individual', 400000)['discount']);
        $this->assertSame(80000, $this->promo->quote($this->code(['restrictions' => ['segment' => ['min_held_sessions' => 1]]])->code, $paidClient, $this->psy, 'individual', 400000)['discount']);
    }

    public function test_promo_codes_do_not_apply_to_corporate_sessions(): void
    {
        $client = $this->userWithRole('client');
        $participation = $this->corporateParticipation($client);
        $this->app->instance(CorporateCoverage::class, new class($participation) implements CorporateCoverage
        {
            public function __construct(private CorporateParticipation $participation) {}

            public function coverFor(User $client, string $format): ?CorporateParticipation
            {
                return $this->participation;
            }

            public function consume(TherapySession $session): void {}

            public function release(TherapySession $session): void {}
        });
        $promo = app(PromoCodes::class);
        $code = $this->code();

        $this->assertRejected(fn () => $promo->quote($code->code, $client, $this->psy, 'individual', 400000), 'корпоративной программе');
        $session = $this->bookedSession($client, ['corporate_participation_id' => $participation->id]);
        $this->assertRejected(fn () => $promo->reserve($code->code, $client, $session), 'корпоративной программе');
    }

    public function test_reserve_counts_limits_and_one_code_per_session(): void
    {
        $a = $this->userWithRole('client');
        $b = $this->userWithRole('client');
        $code = $this->code(['total_limit' => 1]);
        $session = $this->bookedSession($a);

        $this->promo->reserve(strtolower($code->code), $a, $session);
        $code->refresh();
        $this->assertSame(1, (int) $code->uses_count);
        $this->assertSame('exhausted', $code->status);
        $this->assertDatabaseHas('promo_redemptions', ['promo_code_id' => $code->id, 'therapy_session_id' => $session->id, 'status' => 'reserved', 'discount_amount' => 80000]);
        $this->assertDatabaseHas('domain_events', ['name' => 'promo.code.exhausted', 'aggregate_id' => $code->id]);

        // Repeating the reservation for the same session is idempotent; a second code does not stack.
        $this->promo->reserve($code->code, $a, $session);
        $this->assertSame(1, PromoRedemption::count());
        $this->assertRejected(fn () => $this->promo->reserve($this->code()->code, $a, $session), 'промокоды не суммируются');

        // The limit is exhausted for everybody else.
        $this->assertRejected(fn () => $this->promo->quote($code->code, $b, $this->psy, 'individual', 400000), 'Лимит применений');

        // Free cancellation restores the use (BR-PROMO-09): the code is active again.
        $this->promo->restore($session);
        $code->refresh();
        $this->assertSame(0, (int) $code->uses_count);
        $this->assertSame('active', $code->status);
        $this->assertSame('restored', PromoRedemption::sole()->status);

        $sessionB = $this->bookedSession($b);
        $this->promo->reserve($code->code, $b, $sessionB);
        $this->promo->consume($sessionB);
        $this->promo->consume($sessionB);
        $applied = PromoRedemption::where('therapy_session_id', $sessionB->id)->sole();
        $this->assertSame('applied', $applied->status);
        $this->assertNotNull($applied->applied_at);
    }

    public function test_per_user_limit_and_first_session_on_another_booking(): void
    {
        $client = $this->userWithRole('client');
        $code = $this->code(['per_user_limit' => 1]);
        $this->promo->reserve($code->code, $client, $this->bookedSession($client));
        $this->assertRejected(fn () => $this->promo->quote($code->code, $client, $this->psy, 'individual', 400000), 'уже использовали');

        $firstA = $this->code(['type' => 'first_session', 'value' => 50]);
        $firstB = $this->code(['type' => 'first_session', 'value' => 30]);
        $newcomer = $this->userWithRole('client');
        $this->promo->reserve($firstA->code, $newcomer, $this->bookedSession($newcomer));
        $this->assertRejected(fn () => $this->promo->quote($firstB->code, $newcomer, $this->psy, 'individual', 400000), 'уже применена к другой записи');
    }

    public function test_restore_after_the_end_of_the_period_expires_the_code(): void
    {
        $client = $this->userWithRole('client');
        $code = $this->code(['total_limit' => 1, 'valid_until' => now()->addDay()]);
        $session = $this->bookedSession($client);
        $this->promo->reserve($code->code, $client, $session);
        $this->assertSame('exhausted', $code->fresh()->status);

        $this->travel(2)->days();
        $this->promo->restore($session);
        $this->assertSame('expired', $code->fresh()->status);
    }

    public function test_schedule_moves_codes_through_their_period(): void
    {
        $scheduled = $this->code(['valid_from' => now()->addHour(), 'valid_until' => now()->addDays(10)], 'scheduled');
        $ending = $this->code(['valid_until' => now()->addMinutes(30)]);
        $exhausted = $this->code(['valid_until' => now()->addMinutes(30), 'total_limit' => 1, 'uses_count' => 1], 'exhausted');

        $this->travel(2)->hours();
        $this->artisan('promo:sync-statuses')->assertSuccessful();

        $this->assertSame('active', $scheduled->fresh()->status);
        $this->assertSame('expired', $ending->fresh()->status);
        $this->assertSame('expired', $exhausted->fresh()->status);
    }

    public function test_statistics_count_uses_discounts_and_conversion(): void
    {
        $code = $this->code(['per_user_limit' => null]);
        $client = $this->userWithRole('client');
        $s1 = $this->bookedSession($client);
        $s2 = $this->bookedSession($client);
        $s3 = $this->bookedSession($client);
        foreach ([$s1, $s2, $s3] as $s) {
            $this->promo->reserve($code->code, $client, $s);
        }
        $this->promo->consume($s1);
        $this->promo->restore($s2);

        $stats = app(PromoAdminService::class)->statsFor([$code->id])[$code->id];
        $this->assertSame(3, $stats['reserved_total']);
        $this->assertSame(1, $stats['applied']);
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(1, $stats['restored']);
        $this->assertSame(80000, $stats['discount_sum']);
        $this->assertSame(33.3, $stats['conversion']);
        $this->assertSame(2, (int) $code->fresh()->uses_count);
    }
}
