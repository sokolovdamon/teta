<?php

namespace Tests\Support;

use App\Modules\Payments\Gateway\BindingRequest;
use App\Modules\Payments\Gateway\ChargeRequest;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\PaymentRequest;
use App\Modules\Payments\Gateway\PayoutRequest;
use App\Modules\Payments\Gateway\RefundRequest;
use App\Modules\Payments\Gateway\WebhookEvent;
use Illuminate\Http\Request;
use LogicException;
use RuntimeException;

/**
 * In-memory PaymentGateway for PAYOUT/PROMO tests (the emulator belongs to PAY). Payouts are deduplicated by the
 * idempotency key like a real provider: repeating a key never creates a second payout.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, PayoutRequest> distinct payouts by idempotency key */
    public array $payouts = [];

    public int $payoutCalls = 0;

    public string $payoutResult = GatewayOperation::PENDING;

    public ?string $payoutStatusResult = null;

    public bool $throwOnPayout = false;

    /** @var array<string, BindingRequest> */
    public array $bindings = [];

    public string $bindingResult = GatewayOperation::REQUIRES_ACTION;

    public string $bindingStatusResult = GatewayOperation::SUCCEEDED;

    public array $card = ['token' => 'tok_payout_0001', 'mask' => '2200 00** **** 0001', 'brand' => 'MIR', 'exp_month' => 12, 'exp_year' => 2030];

    public function name(): string
    {
        return 'fake';
    }

    public function createBinding(BindingRequest $request): GatewayOperation
    {
        $this->bindings[$request->idempotencyKey] = $request;

        return match ($this->bindingResult) {
            GatewayOperation::SUCCEEDED => new GatewayOperation(GatewayOperation::SUCCEEDED, 'bind_'.md5($request->idempotencyKey), card: $this->card),
            GatewayOperation::DECLINED => new GatewayOperation(GatewayOperation::DECLINED, errorCode: 'card_declined', errorCategory: 'new_card'),
            default => new GatewayOperation(GatewayOperation::REQUIRES_ACTION, 'bind_'.md5($request->idempotencyKey), '/pay/emulator/'.md5($request->idempotencyKey)),
        };
    }

    public function status(string $idempotencyKey): GatewayOperation
    {
        return $this->bindingStatusResult === GatewayOperation::SUCCEEDED
            ? new GatewayOperation(GatewayOperation::SUCCEEDED, 'bind_'.md5($idempotencyKey), card: $this->card)
            : new GatewayOperation($this->bindingStatusResult, 'bind_'.md5($idempotencyKey));
    }

    public function payout(PayoutRequest $request): GatewayOperation
    {
        $this->payoutCalls++;
        if ($this->throwOnPayout) {
            throw new RuntimeException('Connection reset by peer');
        }
        $this->payouts[$request->idempotencyKey] ??= $request;

        return $this->operation($request->idempotencyKey, $this->payoutResult);
    }

    public function payoutStatus(string $idempotencyKey): GatewayOperation
    {
        return $this->operation($idempotencyKey, $this->payoutStatusResult ?? $this->payoutResult);
    }

    public function chargeToken(ChargeRequest $request): GatewayOperation
    {
        throw new LogicException('Not used in PAYOUT tests');
    }

    public function createPayment(PaymentRequest $request): GatewayOperation
    {
        throw new LogicException('Not used in PAYOUT tests');
    }

    public function refund(RefundRequest $request): GatewayOperation
    {
        throw new LogicException('Not used in PAYOUT tests');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new LogicException('Not used in PAYOUT tests');
    }

    /** Distinct payouts actually created at the provider. */
    public function distinctPayouts(): int
    {
        return count($this->payouts);
    }

    public static function gatewayId(string $key): string
    {
        return 'po_'.substr(md5($key), 0, 12);
    }

    private function operation(string $key, string $status): GatewayOperation
    {
        return new GatewayOperation(
            $status,
            self::gatewayId($key),
            errorCode: $status === GatewayOperation::DECLINED ? 'card_blocked' : null,
            errorCategory: $status === GatewayOperation::DECLINED ? 'new_card' : null,
        );
    }
}
