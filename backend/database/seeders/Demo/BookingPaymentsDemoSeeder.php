<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Booking\Models\SessionTimeRequest;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Payments\Gateway\Emulator\EmulatorCard;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo data for CL-03, CL-07, PRO-04, ADM-04 and ADM-07 (idempotent): a bound emulator card of client@teta.local,
 * an upcoming booked session with a scheduled charge, a session paid from the balance, a session cancelled by
 * the psychologist awaiting the client's choice, and an open "Нет подходящего времени" request.
 */
class BookingPaymentsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@teta.local')->first();
        $anna = Psychologist::whereHas('user', fn ($q) => $q->where('email', 'anna.sokolova@teta.local'))->first();
        $mikhail = Psychologist::whereHas('user', fn ($q) => $q->where('email', 'mihail.orlov@teta.local'))->first()
            ?? Psychologist::where('id', '!=', $anna?->id)->orderBy('created_at')->first();
        $ekaterina = Psychologist::whereNotIn('id', array_filter([$anna?->id, $mikhail?->id]))->orderBy('created_at')->first();
        if (! $client || ! $anna || ! $mikhail || TherapySession::where('idempotency_key', 'demo-b1-booked')->exists()) {
            return;
        }

        // Emulator card "4111 1111 1111 1111" bound by the client.
        $token = 'emu_tok_demo_client';
        EmulatorCard::firstOrCreate(['token' => $token], [
            'user_id' => $client->id, 'card_mask' => '•••• 1111', 'card_brand' => 'Visa', 'exp_month' => 12, 'exp_year' => 2030, 'behavior' => 'success',
        ]);
        PaymentMethod::firstOrCreate(['user_id' => $client->id, 'purpose' => 'payment', 'card_mask' => '•••• 1111'], [
            'gateway' => 'emulator', 'token' => $token, 'card_brand' => 'Visa', 'exp_month' => 12, 'exp_year' => 2030, 'is_default' => true, 'status' => 'active',
        ]);

        $balance = app(BalanceService::class);
        $balance->credit($client->id, 600000, 'admin', null, null, false, 'Демо-начисление');
        $balance->credit($client->id, 300000, 'certificate', null, null, true, 'Демо-сертификат');

        $tz = 'Europe/Moscow';
        $params = app(BookingService::class)->paramSnapshot();

        // 1. Booked, charged automatically 12 h before the start.
        $start = CarbonImmutable::now($tz)->addDays(3)->setTime(12, 0);
        $booked = $this->session($client, $anna, $start, TherapySession::BOOKED, $params, 'demo-b1-booked');
        $task = new ChargeTask;
        $task->forceFill([
            'therapy_session_id' => $booked->id, 'user_id' => $client->id, 'amount' => $booked->amount_due,
            'due_at' => $booked->charge_due_at, 'deadline_at' => $booked->charge_deadline_at, 'status' => 'scheduled',
        ])->save();
        $task->recordInitialState(null, ['session_id' => $booked->id]);

        // 2. Paid from the balance (certificate funds first).
        $start2 = CarbonImmutable::now($tz)->addDays(6)->setTime(17, 0);
        $paid = $this->session($client, $anna, $start2, TherapySession::PAID, $params, 'demo-b1-paid', [
            'payment_source' => 'balance', 'paid_at' => now(), 'paid_balance' => (int) $anna->price_individual,
            'amount_charged' => (int) $anna->price_individual,
        ]);
        $op = $balance->reserveSpend($client->id, (int) $anna->price_individual, $paid);
        if ($op) {
            $balance->confirmSpend($op, $paid);
            $paid->forceFill(['paid_certificate' => (int) $op->certificate_amount])->save();
        }

        // 3. Cancelled by the psychologist after the charge: the client chooses a refund or a free reschedule.
        $start3 = CarbonImmutable::now($tz)->addDays(2)->setTime(18, 0);
        $cancelled = $this->session($client, $mikhail, $start3, TherapySession::CANCELLED_BY_PSY, $params, 'demo-b1-psycancel', [
            'payment_source' => 'balance', 'paid_at' => now()->subDay(), 'paid_balance' => (int) $mikhail->price_individual,
            'amount_charged' => (int) $mikhail->price_individual, 'cancelled_at' => now(), 'cancel_kind' => 'psy_cancel',
            'cancel_reason' => 'Психолог заболел', 'client_choice' => 'pending', 'choice_deadline_at' => $start3,
        ]);
        $credit = $balance->reserveSpend($client->id, (int) $mikhail->price_individual, $cancelled);
        if ($credit) {
            $balance->confirmSpend($credit, $cancelled);
        }

        if ($ekaterina) {
            $request = new SessionTimeRequest;
            $request->forceFill([
                'client_id' => $client->id, 'psychologist_id' => $ekaterina->id, 'format' => 'individual',
                'preferred' => [['weekday' => 6, 'from' => '10:00', 'to' => '14:00'], ['weekday' => 7, 'from' => '11:00', 'to' => '15:00']],
                'comment' => 'Удобно только в выходные', 'status' => 'open',
            ])->save();
        }
    }

    /** @param  array<string, mixed>  $params */
    private function session(User $client, Psychologist $p, CarbonImmutable $start, string $status, array $params, string $key, array $extra = []): TherapySession
    {
        $price = (int) $p->price_individual;
        $session = new TherapySession;
        $session->forceFill([
            'client_id' => $client->id,
            'psychologist_id' => $p->id,
            'format' => 'individual',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(50),
            'duration_min' => 50,
            'price' => $price,
            'discount' => 0,
            'amount_due' => $price,
            'charge_due_at' => $start->subMinutes((int) $params['P-CHARGE-OFFSET']),
            'charge_deadline_at' => $start->subMinutes((int) $params['P-CHARGE-DEADLINE']),
            'client_timezone' => $client->timezone ?: 'Europe/Moscow',
            'source' => 'catalog',
            'params' => $params,
            'idempotency_key' => $key,
            ...$extra,
            'status' => $status,
        ])->save();
        $session->recordInitialState($client->id, ['kind' => 'booked', 'payment_mode' => 'demo', 'price' => $price]);

        return $session;
    }
}
