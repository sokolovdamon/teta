<?php

namespace App\Modules\Payments;

use App\Modules\Payments\Gateway\Emulator\EmulatorGateway;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\WebhookEvent;
use App\Modules\Payments\Gateway\WebhookHandlers;
use App\Modules\Payments\Models\CardBinding;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Purposes\CertificatePurpose;
use App\Modules\Payments\Purposes\SessionChargePurpose;
use App\Modules\Payments\Services\CardService;
use App\Modules\Payments\Services\PaymentPurposes;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * PAY: the payment gateway is chosen by PAYMENT_GATEWAY (only "emulator" until Q-43 is answered, DEC-38).
 * Webhook prefixes "payment.", "refund.", "binding." are handled here; PAYOUT registers "payout.".
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function ($app) {
            return match (config('payments.gateway', 'emulator')) {
                'emulator' => $app->make(EmulatorGateway::class),
                default => throw new InvalidArgumentException('Unknown payment gateway '.config('payments.gateway')),
            };
        });
    }

    public function boot(): void
    {
        Relation::morphMap([
            'card_binding' => CardBinding::class,
            'payment_refund' => PaymentRefund::class,
            'payment_method' => PaymentMethod::class,
        ]);

        WebhookHandlers::register('payment.', fn (WebhookEvent $e) => app(PaymentService::class)->handleWebhook($e));
        WebhookHandlers::register('refund.', fn (WebhookEvent $e) => app(PaymentService::class)->handleRefundWebhook($e));
        WebhookHandlers::register('binding.', fn (WebhookEvent $e) => app(CardService::class)->handleWebhook($e));

        PaymentPurposes::register('session', SessionChargePurpose::class);
        PaymentPurposes::register('certificate', CertificatePurpose::class);
    }
}
