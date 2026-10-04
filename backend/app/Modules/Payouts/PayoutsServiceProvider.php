<?php

namespace App\Modules\Payouts;

use App\Modules\Payments\Gateway\WebhookEvent;
use App\Modules\Payments\Gateway\WebhookHandlers;
use App\Modules\Payouts\Listeners\ComplaintRefundedListener;
use App\Modules\Payouts\Listeners\SessionOutcomeListener;
use App\Modules\Payouts\Models\PayoutRegistry;
use App\Modules\Payouts\Services\AccrualService;
use App\Modules\Payouts\Services\PayeeBalanceService;
use App\Modules\Payouts\Services\PayoutRegistryService;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/** PAYOUT (ST-06, ST-07, SEQ-08): accruals from BOOK/PAY events, weekly registry, payout webhooks. */
class PayoutsServiceProvider extends ServiceProvider
{
    /** Session outcomes that create, keep or reverse an accrual (including admin corrections between them). */
    public const SESSION_EVENTS = [
        'book.session.held',
        'book.session.client_no_show',
        'book.session.cancelled_by_client',
        'book.session.psy_no_show',
        'book.session.tech_issue',
    ];

    public function register(): void
    {
        $this->app->singleton(PayeeBalanceService::class);
        $this->app->singleton(AccrualService::class);
        $this->app->singleton(PayoutRegistryService::class);
    }

    public function boot(): void
    {
        Relation::morphMap(['payout_registry' => PayoutRegistry::class]);

        foreach (self::SESSION_EVENTS as $event) {
            Outbox::listen($event, SessionOutcomeListener::class);
        }
        Outbox::listen('pay.complaint.refunded', ComplaintRefundedListener::class);

        WebhookHandlers::register('payout.', fn (WebhookEvent $event) => app(PayoutRegistryService::class)->handleWebhook($event));
    }
}
