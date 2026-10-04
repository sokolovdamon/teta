<?php

namespace Tests\Feature\Booking;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Payments\Gateway\Emulator\EmulatorCheckout;
use App\Modules\Payments\Gateway\Emulator\EmulatorOperation;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Payments\Services\CardService;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Events\DomainEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPsychologists;

/** Shared setup for BOOK and PAY tests: fixed clock, clients, cards bound through the emulator, bookings. */
trait BookingFixtures
{
    use CreatesPsychologists;

    /** Monday 2026-10-05 08:00 MSK. */
    protected function freezeClock(string $utc = '2026-10-05 05:00:00'): void
    {
        $now = CarbonImmutable::parse($utc, 'UTC');
        CarbonImmutable::setTestNow($now);
        $this->travelTo($now);
    }

    protected function moveTo(string $msk): CarbonImmutable
    {
        $at = CarbonImmutable::parse($msk, 'Europe/Moscow');
        CarbonImmutable::setTestNow($at);
        $this->travelTo($at);

        return $at;
    }

    protected function client(array $attrs = []): User
    {
        $user = User::factory()->withRole('client')->create(['timezone' => 'Europe/Moscow', 'birth_date' => '1990-05-01', ...$attrs]);

        return $user->fresh();
    }

    protected function actAs(User $user): User
    {
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    /** Bind a card through the emulator checkout (test card number decides the behaviour). */
    protected function bindCard(User $user, string $number = '4111 1111 1111 1111'): ?PaymentMethod
    {
        $binding = app(CardService::class)->startBinding($user, '/client/payments');
        $op = EmulatorOperation::findOrFail($binding->gateway_id);
        $op = app(EmulatorCheckout::class)->submitCard($op, ['card_number' => $number, 'exp_month' => 12, 'exp_year' => 2030, 'cvc' => '123']);
        if ($op->status === 'awaiting_3ds') {
            app(EmulatorCheckout::class)->confirm3ds($op, true);
        }

        return PaymentMethod::where('user_id', $user->id)->where('status', 'active')->latest()->first();
    }

    protected function credit(User $user, int $amount, bool $certificate = false): void
    {
        app(BalanceService::class)->credit($user->id, $amount, $certificate ? 'certificate' : 'admin', null, null, $certificate);
    }

    /** Book through the service (deferred when the start is later than P-CHARGE-OFFSET). */
    protected function book(User $client, Psychologist $p, string $mskStart, array $extra = []): TherapySession
    {
        $result = app(BookingService::class)->book($client, [
            'psychologist_id' => $p->id,
            'format' => 'individual',
            'starts_at' => CarbonImmutable::parse($mskStart, 'Europe/Moscow')->toIso8601String(),
            ...$extra,
        ]);

        return $result['session']->fresh();
    }

    protected function msk(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Europe/Moscow');
    }

    protected function events(string $name): Collection
    {
        return DomainEvent::where('name', $name)->orderBy('occurred_at')->get();
    }

    protected function balanceOf(User $user): array
    {
        return app(BalanceService::class)->summary($user->id);
    }
}
