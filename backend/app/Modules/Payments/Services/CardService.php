<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Booking\Support\SessionTime;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Gateway\BindingRequest;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\WebhookEvent;
use App\Modules\Payments\Models\CardBinding;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cards (CL-07, WIZ-06, PRO-09): binding with the payer present (3-D Secure in the provider's form), list, removal.
 * Only the gateway token (encrypted), mask, brand and expiry are stored (TZ v2, section 8).
 * Removal is always allowed (376-ФЗ): the token is never used again; unpaid bookings are cancelled by the system
 * at P-CHARGE-DEADLINE if no other card is bound (ST-02).
 */
class CardService
{
    public function __construct(private PaymentGateway $gateway, private Notifier $notifier) {}

    /** @return Collection<int, PaymentMethod> */
    public function active(string $userId, string $purpose = 'payment'): Collection
    {
        return PaymentMethod::where('user_id', $userId)->where('purpose', $purpose)->where('status', 'active')
            ->orderByDesc('is_default')->orderByDesc('created_at')->get();
    }

    public function defaultCard(string $userId, string $purpose = 'payment'): ?PaymentMethod
    {
        return $this->active($userId, $purpose)->first();
    }

    public function startBinding(User $user, ?string $returnPath, string $purpose = 'payment'): CardBinding
    {
        $binding = CardBinding::create([
            'user_id' => $user->id,
            'gateway' => $this->gateway->name(),
            'idempotency_key' => 'bind:'.Str::uuid7(),
            'status' => CardBinding::PENDING,
            'purpose' => $purpose,
            'return_path' => $returnPath,
        ]);
        $returnUrl = rtrim((string) config('app.frontend_url'), '/').'/pay/return?binding='.$binding->id;

        $op = $this->gateway->createBinding(new BindingRequest($binding->idempotency_key, $user->id, $returnUrl));
        $binding->update(['gateway_id' => $op->gatewayId, 'confirmation_url' => $op->confirmationUrl]);
        if (in_array($op->status, [GatewayOperation::SUCCEEDED, GatewayOperation::DECLINED], true)) {
            $this->applyBinding($binding, $op);
        }

        return $binding->fresh();
    }

    public function handleWebhook(WebhookEvent $event): void
    {
        $binding = CardBinding::where('idempotency_key', $event->idempotencyKey)->first();
        if ($binding) {
            $this->applyBinding($binding, $event->operation);
        }
    }

    /** Recover a binding whose webhook was lost. */
    public function resolve(CardBinding $binding): CardBinding
    {
        if ($binding->status === CardBinding::PENDING) {
            $op = $this->gateway->status($binding->idempotency_key);
            if ($op->status !== GatewayOperation::REQUIRES_ACTION) {
                $this->applyBinding($binding, $op);
            }
        }

        return $binding->fresh();
    }

    public function applyBinding(CardBinding $binding, GatewayOperation $op): void
    {
        DB::transaction(function () use ($binding, $op) {
            $b = CardBinding::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            if ($b->status !== CardBinding::PENDING) {
                return;
            }
            if ($op->status === GatewayOperation::SUCCEEDED && $op->card) {
                $method = $this->store($b->user_id, $op->card, $b->purpose);
                $b->forceFill(['status' => CardBinding::SUCCEEDED, 'payment_method_id' => $method->id, 'confirmation_url' => null])->save();
                Outbox::record('pay.card.bound', $method, ['user_id' => $b->user_id, 'purpose' => $b->purpose], $b->user_id);
                if ($b->purpose === 'payment') {
                    // Charges that waited for another card are retried with the new one right away (ST-02).
                    ChargeTask::where('user_id', $b->user_id)->where('status', 'retry_wait')
                        ->whereIn('last_error_category', ['new_card', 'no_retry'])
                        ->update(['next_attempt_at' => now()]);
                }
                $this->notifier->send($b->user, 'pay.card_bound', ['card' => $method->card_mask], $b->purpose === 'payout' ? '/pro/payouts' : '/client/payments');
            } elseif (in_array($op->status, [GatewayOperation::DECLINED, GatewayOperation::SUCCEEDED], true)) {
                $b->forceFill([
                    'status' => CardBinding::DECLINED,
                    'error_code' => $op->errorCode ?? 'declined',
                    'error_category' => $op->errorCategory,
                    'confirmation_url' => null,
                ])->save();
            }
        });
    }

    /** @param  array{token: string, mask?: string|null, brand?: string|null, exp_month?: int|null, exp_year?: int|null}  $card */
    public function store(string $userId, array $card, string $purpose = 'payment'): PaymentMethod
    {
        return DB::transaction(function () use ($userId, $card, $purpose) {
            PaymentMethod::where('user_id', $userId)->where('purpose', $purpose)->update(['is_default' => false]);

            return PaymentMethod::create([
                'user_id' => $userId,
                'gateway' => $this->gateway->name(),
                'token' => $card['token'],
                'purpose' => $purpose,
                'card_mask' => $card['mask'] ?? null,
                'card_brand' => $card['brand'] ?? null,
                'exp_month' => $card['exp_month'] ?? null,
                'exp_year' => $card['exp_year'] ?? null,
                'is_default' => true,
                'status' => 'active',
            ]);
        });
    }

    public function makeDefault(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            PaymentMethod::where('user_id', $method->user_id)->where('purpose', $method->purpose)->update(['is_default' => false]);
            $method->forceFill(['is_default' => true])->save();
        });
    }

    /** @return array{unpaid_sessions: int, deadline: string|null, has_other_card: bool} */
    public function remove(PaymentMethod $method): array
    {
        return DB::transaction(function () use ($method) {
            $method->forceFill(['status' => 'removed', 'removed_at' => now(), 'is_default' => false, 'token' => 'removed'])->save();
            $next = $this->defaultCard($method->user_id, $method->purpose);
            if ($next && ! $next->is_default) {
                $next->forceFill(['is_default' => true])->save();
            }
            Outbox::record('pay.card.removed', $method, ['user_id' => $method->user_id, 'purpose' => $method->purpose], $method->user_id);

            $tasks = ChargeTask::where('user_id', $method->user_id)->whereIn('status', ['scheduled', 'retry_wait'])->orderBy('deadline_at')->get();
            $result = [
                'unpaid_sessions' => $tasks->count(),
                'deadline' => $tasks->first()?->deadline_at?->toIso8601String(),
                'has_other_card' => $next !== null,
            ];
            if ($method->purpose === 'payment' && ! $next && $tasks->isNotEmpty()) {
                $user = $method->user;
                $this->notifier->send($user, 'pay.card_removed_unpaid', [
                    'count' => $tasks->count(),
                    'deadline' => SessionTime::format($tasks->first()->deadline_at, $user->timezone),
                ], '/client/payments');
            }

            return $result;
        });
    }
}
