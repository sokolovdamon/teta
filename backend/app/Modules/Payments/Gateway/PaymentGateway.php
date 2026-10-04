<?php

namespace App\Modules\Payments\Gateway;

use Illuminate\Http\Request;

/**
 * Contract of the payment service (DEC-38, Q-43). Until the customer picks a provider, the platform works on
 * EmulatorGateway; a real adapter later implements the same contract and nothing else changes in BOOK, SUPERV,
 * PAYOUT, PROMO or B2B. Every operation carries an idempotency key; repeating a key never creates a second
 * operation. Webhooks are verified by signature before processing.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Bind a card with the payer present (3-D Secure); the result contains a confirmation URL. */
    public function createBinding(BindingRequest $request): GatewayOperation;

    /** Charge a saved card token without the payer (autocharge 12 h before the session). */
    public function chargeToken(ChargeRequest $request): GatewayOperation;

    /** Payment with the payer present (late booking, retry with another card, certificate, event). */
    public function createPayment(PaymentRequest $request): GatewayOperation;

    /** Full or partial refund of a successful payment; a refund receipt is registered. */
    public function refund(RefundRequest $request): GatewayOperation;

    /** Status of an operation by its idempotency key (unknown status, lost webhook). */
    public function status(string $idempotencyKey): GatewayOperation;

    /** Payout to a self-employed psychologist's card token. */
    public function payout(PayoutRequest $request): GatewayOperation;

    public function payoutStatus(string $idempotencyKey): GatewayOperation;

    /** Verify the signature and parse a webhook; throws InvalidWebhook when the signature is wrong. */
    public function parseWebhook(Request $request): WebhookEvent;
}
