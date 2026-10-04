<?php

namespace App\Modules\Payouts\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Payments\Gateway\BindingRequest;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payouts\Models\PayoutCardBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PRO-09: card of the self-employed psychologist for payouts (payment method with purpose "payout").
 * Binding goes through the gateway with the payer present (3-D Secure page of the emulator / provider);
 * after the redirect back PRO-09 confirms the binding, which asks the gateway for the operation status.
 * Card numbers are never stored, only the gateway token and a mask (TZ v2, section 8).
 */
class PayoutCardService
{
    public const KEY_PREFIX = 'payout-card:';

    public static function activeCard(string $userId): ?PaymentMethod
    {
        return PaymentMethod::where('user_id', $userId)
            ->where('purpose', 'payout')
            ->where('status', 'active')
            ->latest()
            ->first();
    }

    public function start(User $user): PayoutCardBinding
    {
        $binding = PayoutCardBinding::create(['user_id' => $user->id, 'idempotency_key' => self::KEY_PREFIX.Str::uuid(), 'status' => 'pending']);
        $returnUrl = rtrim((string) config('app.frontend_url'), '/').'/pro/payouts?binding='.$binding->id;

        $operation = $this->gateway()->createBinding(new BindingRequest($binding->idempotency_key, $user->id, $returnUrl));
        $binding->forceFill(['confirmation_url' => $operation->confirmationUrl])->save();

        return $this->apply($binding, $operation);
    }

    /** Called after the redirect back from the confirmation page (or polled while the gateway is still processing). */
    public function confirm(PayoutCardBinding $binding): PayoutCardBinding
    {
        if ($binding->status !== 'pending') {
            return $binding;
        }
        try {
            $operation = $this->gateway()->status($binding->idempotency_key);
        } catch (Throwable $e) {
            Log::warning('Payout card binding status failed: '.$e->getMessage());

            return $binding;
        }

        return $this->apply($binding, $operation);
    }

    public function remove(User $user): void
    {
        DB::transaction(function () use ($user) {
            $cards = PaymentMethod::where('user_id', $user->id)->where('purpose', 'payout')->where('status', 'active')->lockForUpdate()->get();
            foreach ($cards as $card) {
                $card->forceFill(['status' => 'removed', 'removed_at' => now(), 'is_default' => false])->save();
            }
            if ($cards->isNotEmpty()) {
                Audit::log('PRO-09', 'payout_card.removed', $user, ['cards' => $cards->pluck('card_mask')->all()], userId: $user->id);
            }
        });
    }

    private function apply(PayoutCardBinding $binding, GatewayOperation $operation): PayoutCardBinding
    {
        if ($operation->status === GatewayOperation::DECLINED) {
            $binding->forceFill(['status' => 'declined', 'error_code' => $operation->errorCode])->save();
        } elseif ($operation->succeeded() && $operation->card) {
            $this->finalize($binding, $operation->card);
        }

        return $binding->fresh();
    }

    /** @param  array{token: string, mask: string, brand: string, exp_month: int, exp_year: int}  $card */
    private function finalize(PayoutCardBinding $binding, array $card): void
    {
        DB::transaction(function () use ($binding, $card) {
            $binding = PayoutCardBinding::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            if ($binding->status !== 'pending') {
                return;
            }
            $previous = PaymentMethod::where('user_id', $binding->user_id)->where('purpose', 'payout')->where('status', 'active')->get();
            foreach ($previous as $old) {
                $old->forceFill(['status' => 'removed', 'removed_at' => now(), 'is_default' => false])->save();
            }

            // PAY may already have saved the same token from the binding webhook: reuse it as the payout card.
            $method = PaymentMethod::where('user_id', $binding->user_id)->where('token', $card['token'])->first() ?? new PaymentMethod;
            $method->forceFill([
                'user_id' => $binding->user_id,
                'gateway' => $this->gateway()->name(),
                'token' => $card['token'],
                'purpose' => 'payout',
                'card_mask' => $card['mask'] ?? null,
                'card_brand' => $card['brand'] ?? null,
                'exp_month' => $card['exp_month'] ?? null,
                'exp_year' => $card['exp_year'] ?? null,
                'is_default' => true,
                'status' => 'active',
                'removed_at' => null,
            ])->save();

            $binding->forceFill(['status' => 'succeeded', 'payment_method_id' => $method->id])->save();
            Audit::log('PRO-09', 'payout_card.bound', $binding->user, ['card_mask' => $method->card_mask], userId: $binding->user_id);
        });
    }

    private function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }
}
