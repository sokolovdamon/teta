<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Gateway\ChargeRequest;
use App\Modules\Payments\Gateway\Emulator\EmulatorCheckout;
use App\Modules\Payments\Gateway\Emulator\EmulatorOperation;
use App\Modules\Payments\Gateway\Emulator\EmulatorWebhooks;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\PayoutRequest;
use App\Modules\Payments\Gateway\RefundRequest;
use App\Modules\Payments\Models\CardBinding;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\WebhookInbox;
use App\Modules\Payments\Services\CardService;
use App\Modules\Payments\Services\ReceiptService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Booking\BookingFixtures;
use Tests\TestCase;

class EmulatorGatewayTest extends TestCase
{
    use BookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    public function test_card_binding_through_checkout_creates_encrypted_token_and_webhook_is_processed_once(): void
    {
        $user = $this->client();
        $this->actAs($user);
        $res = $this->postJson('/api/v1/payments/cards', ['return_path' => '/client/payments'])->assertCreated();
        $confirmation = $res->json('data.confirmation_url');
        $this->assertStringContainsString('/pay/emulator/', $confirmation);
        $opId = basename($confirmation);

        $this->getJson("/api/v1/payments/emulator/operations/{$opId}")->assertOk()
            ->assertJsonPath('data.kind', 'binding')
            ->assertJsonFragment(['number' => '4000 0000 0000 3220']);

        $this->postJson("/api/v1/payments/emulator/operations/{$opId}/card", [
            'card_number' => '4111 1111 1111 1111', 'exp_month' => 12, 'exp_year' => 30, 'cvc' => '123',
        ])->assertOk()->assertJsonPath('data.status', 'succeeded');

        $method = PaymentMethod::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('•••• 1111', $method->card_mask);
        $this->assertStringStartsWith('emu_tok_', $method->token);
        $this->assertStringNotContainsString('emu_tok_', (string) DB::table('payment_methods')->where('id', $method->id)->value('token'));
        $this->assertSame(CardBinding::SUCCEEDED, CardBinding::firstOrFail()->status);
        $this->getJson('/api/v1/payments/cards')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.token');

        // The provider redelivers the same event: 2xx, not processed again.
        $op = EmulatorOperation::findOrFail($opId);
        [$status, $body] = app(EmulatorWebhooks::class)->deliver(
            $raw = (string) json_encode(EmulatorWebhooks::payload($op, 'binding.succeeded')),
            EmulatorWebhooks::sign($raw),
        );
        $this->assertSame(200, $status);
        $this->assertTrue($body['duplicate']);
        $this->assertSame(1, WebhookInbox::where('event_type', 'binding.succeeded')->count());
        $this->assertSame(1, PaymentMethod::where('user_id', $user->id)->count());
    }

    public function test_three_d_secure_confirm_and_decline(): void
    {
        $user = $this->client();
        $checkout = app(EmulatorCheckout::class);

        $b1 = app(CardService::class)->startBinding($user, null);
        $op = $checkout->submitCard(EmulatorOperation::findOrFail($b1->gateway_id), ['card_number' => '4000000000003220', 'exp_month' => 1, 'exp_year' => 2031, 'cvc' => '123']);
        $this->assertSame('awaiting_3ds', $op->status);
        $this->assertSame(CardBinding::PENDING, $b1->fresh()->status);
        $checkout->confirm3ds($op, true);
        $this->assertSame(CardBinding::SUCCEEDED, $b1->fresh()->status);

        $b2 = app(CardService::class)->startBinding($user, null);
        $op2 = $checkout->submitCard(EmulatorOperation::findOrFail($b2->gateway_id), ['card_number' => '4000000000003220', 'exp_month' => 1, 'exp_year' => 2031, 'cvc' => '123']);
        $checkout->confirm3ds($op2, false);
        $this->assertSame(CardBinding::DECLINED, $b2->fresh()->status);
        $this->assertSame('authentication_failed', $b2->fresh()->error_code);
        $this->assertSame(1, PaymentMethod::where('user_id', $user->id)->count());
    }

    public function test_invalid_signature_is_rejected_and_not_processed(): void
    {
        $body = json_encode(['id' => 'evt_x', 'type' => 'payment.succeeded', 'idempotency_key' => 'k', 'object' => ['id' => 'x', 'status' => 'succeeded']]);
        $this->call('POST', '/api/v1/payments/webhooks/emulator', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EMULATOR_SIGNATURE' => 'sha256=bad'], content: $body)
            ->assertStatus(400);
        $this->assertSame(0, WebhookInbox::where('signature_valid', true)->count());
        $this->assertSame(1, WebhookInbox::where('signature_valid', false)->count());
        $this->postJson('/api/v1/payments/webhooks/other', [])->assertNotFound();
    }

    public function test_charge_by_token_is_idempotent_and_test_cards_decline_with_categories(): void
    {
        $user = $this->client();
        $gateway = app(PaymentGateway::class);
        $ok = $this->bindCard($user);

        $first = $gateway->chargeToken(new ChargeRequest('task-1:1', $ok->token, 350000, 'Оплата', ReceiptService::forSession(350000, 'Консультация', $user->email)));
        $again = $gateway->chargeToken(new ChargeRequest('task-1:1', $ok->token, 350000, 'Оплата'));
        $this->assertSame(GatewayOperation::SUCCEEDED, $first->status);
        $this->assertSame($first->gatewayId, $again->gatewayId);
        $this->assertSame(1, EmulatorOperation::where('kind', 'charge')->count());
        $this->assertSame('PREPAYMENT_FULL', $first->raw['receipt']['calculation_method']);

        foreach ([
            ['4000000000000002', 'no_retry'],
            ['4000000000009995', 'retry'],
            ['4000000000000069', 'new_card'],
            ['4000000000003220', 'new_card'],
        ] as $i => [$number, $category]) {
            $card = $this->bindCard($this->client(), $number);
            $result = $gateway->chargeToken(new ChargeRequest("t{$i}:1", $card?->token ?? 'missing', 100000, 'Оплата'));
            $this->assertSame(GatewayOperation::DECLINED, $result->status, $number);
            $this->assertSame($category, $result->errorCategory, $number);
        }
    }

    public function test_amount_ending_in_13_kopecks_emulates_timeout_and_status_query_recovers(): void
    {
        $card = $this->bindCard($this->client());
        $gateway = app(PaymentGateway::class);
        $result = $gateway->chargeToken(new ChargeRequest('slow:1', $card->token, 350013, 'Оплата'));
        $this->assertSame(GatewayOperation::UNKNOWN, $result->status);
        $this->assertNull(EmulatorOperation::where('idempotency_key', 'slow:1')->value('webhook_sent_at'));
        $this->assertSame(GatewayOperation::SUCCEEDED, $gateway->status('slow:1')->status);
        $this->assertSame(GatewayOperation::DECLINED, $gateway->status('never-sent')->status);
    }

    public function test_refunds_with_receipt_and_refund_rejecting_card(): void
    {
        $gateway = app(PaymentGateway::class);
        $card = $this->bindCard($this->client());
        $paid = $gateway->chargeToken(new ChargeRequest('p:1', $card->token, 500000, 'Оплата', ReceiptService::forSession(500000, 'Консультация', null)));
        $refund = $gateway->refund(new RefundRequest('r:1', $paid->gatewayId, 200000, ReceiptService::forSession(200000, 'Возврат', null)));
        $this->assertSame(GatewayOperation::SUCCEEDED, $refund->status);
        $this->assertSame('income_return', $refund->raw['receipt']['kind']);
        $this->assertSame(GatewayOperation::DECLINED, $gateway->refund(new RefundRequest('r:2', $paid->gatewayId, 400000))->status);

        $bad = $this->bindCard($this->client(), '4000000000000077');
        $paid2 = $gateway->chargeToken(new ChargeRequest('p:2', $bad->token, 100000, 'Оплата'));
        $this->assertSame(GatewayOperation::SUCCEEDED, $paid2->status);
        $this->assertSame('refund_rejected', $gateway->refund(new RefundRequest('r:3', $paid2->gatewayId, 100000))->errorCode);
    }

    public function test_payout_reports_result_by_webhook_and_status(): void
    {
        $gateway = app(PaymentGateway::class);
        $card = $this->bindCard($this->client());
        $result = $gateway->payout(new PayoutRequest('payout:1', $card->token, 245000, 'Выплата'));
        $this->assertSame(GatewayOperation::PENDING, $result->status);
        $this->assertSame(GatewayOperation::SUCCEEDED, $gateway->payoutStatus('payout:1')->status);
        $this->assertSame(1, WebhookInbox::where('event_type', 'payout.paid')->count());
    }

    public function test_test_cards_endpoint_documents_behaviour(): void
    {
        $this->getJson('/api/v1/payments/emulator/test-cards')->assertOk()->assertJsonCount(7, 'data');
    }
}
