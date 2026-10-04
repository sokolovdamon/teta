<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Corporate\Models\CorporateParticipation;
use App\Modules\Instance\InstanceConfig;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\GiftCertificate;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcPostgresConnection;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstanceConfig::class);

        Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => new UtcPostgresConnection($pdo, $database, $prefix, $config));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stable names for polymorphic columns; modules may add their own with Relation::morphMap().
        Relation::morphMap([
            'user' => User::class,
            'psychologist' => Psychologist::class,
            'therapy_session' => TherapySession::class,
            'payment' => Payment::class,
            'charge_task' => ChargeTask::class,
            'client_balance_operation' => ClientBalanceOperation::class,
            'charge_complaint' => ChargeComplaint::class,
            'gift_certificate' => GiftCertificate::class,
            'accrual' => Accrual::class,
            'payout' => Payout::class,
            'promo_code' => PromoCode::class,
            'corporate_participation' => CorporateParticipation::class,
        ]);

        // Brute-force protection per address and account; the per-account lock is P-LOGIN-ATTEMPTS (AuthService).
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth:'.$request->ip().'|'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(60)->by('auth-ip:'.$request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
