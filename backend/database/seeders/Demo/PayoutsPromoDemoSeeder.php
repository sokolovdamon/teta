<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Services\AccrualService;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Services\ReferralService;
use App\Modules\Psychologists\Services\ActivityService;
use Illuminate\Database\Seeder;

/** Demo data for PRO-08, PRO-09, ADM-08, ADM-09 and CL-13 (idempotent). */
class PayoutsPromoDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@teta.local')->first();

        foreach ([
            ['WELCOME30', 'Первая сессия со скидкой 30 %', 'first_session', 30, 'active', null, null],
            ['AUTUMN20', 'Осенняя акция −20 %', 'percent', 20, 'active', now()->subDays(3), now()->addMonth()->endOfMonth()],
            ['MINUS1000', 'Скидка 1 000 ₽ на сессию от 4 000 ₽', 'fixed', 100000, 'draft', null, now()->addMonths(2)],
        ] as [$code, $title, $type, $value, $status, $from, $until]) {
            $promo = PromoCode::firstOrNew(['code' => $code]);
            if ($promo->exists) {
                continue;
            }
            $promo->fill([
                'title' => $title, 'type' => $type, 'value' => $value, 'kind' => 'mass', 'source' => 'admin',
                'valid_from' => $from, 'valid_until' => $until, 'per_user_limit' => 1, 'total_limit' => $type === 'first_session' ? null : 500,
                'min_amount' => $type === 'fixed' ? 400000 : null, 'created_by' => $admin?->id,
                'description' => 'Демонстрационный промокод.', 'published_at' => $status === 'active' ? now() : null,
            ]);
            $promo->forceFill(['status' => $status])->save();
            $promo->recordInitialState($admin?->id);
        }

        $client = User::where('email', 'client@teta.local')->first();
        if ($client) {
            app(ReferralService::class)->linkFor($client);
        }

        $anna = User::where('email', 'anna.sokolova@teta.local')->with('psychologist')->first();
        $psy = $anna?->psychologist;
        if (! $anna || ! $psy || ! $client || Accrual::where('user_id', $anna->id)->exists()) {
            return;
        }

        PaymentMethod::firstOrCreate(
            ['user_id' => $anna->id, 'token' => 'demo_payout_card_anna'],
            ['gateway' => 'emulator', 'purpose' => 'payout', 'card_mask' => '2200 70** **** 4415', 'card_brand' => 'MIR', 'exp_month' => 8, 'exp_year' => 2029, 'is_default' => true, 'status' => 'active'],
        );
        app(ActivityService::class)->markMonthMet($psy);

        foreach ([9, 6, 2] as $daysAgo) {
            $start = now()->subDays($daysAgo)->setTime(11, 0);
            $session = new TherapySession([
                'client_id' => $client->id, 'psychologist_id' => $psy->id, 'format' => 'individual',
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(50), 'duration_min' => 50,
                'price' => $psy->price_individual, 'discount' => 0, 'amount_due' => $psy->price_individual,
                'payment_source' => 'card', 'paid_at' => $start->copy()->subHours(12), 'outcome_at' => $start->copy()->addMinutes(50),
                'source' => 'catalog',
            ]);
            $session->forceFill(['status' => TherapySession::HELD])->save();
            app(AccrualService::class)->syncSession($session);
        }
    }
}
