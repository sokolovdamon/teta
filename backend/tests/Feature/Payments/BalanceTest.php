<?php

namespace Tests\Feature\Payments;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\ClientBalance;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\Receipt;
use App\Modules\Payments\Services\BalanceService;
use App\Support\StateMachine\StateTransition;
use Tests\Feature\Booking\BookingFixtures;
use Tests\TestCase;

/** ST-04 (Q-52): credits, spend reservation and reversal, materialised balance, withdrawal of the remainder. */
class BalanceTest extends TestCase
{
    use BookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    public function test_operations_keep_balance_consistent_and_never_negative(): void
    {
        $user = $this->client();
        $svc = app(BalanceService::class);
        $svc->credit($user->id, 300000, 'admin');
        $svc->credit($user->id, 200000, 'certificate', certificate: true);
        $this->assertSame(['available' => 500000, 'certificate_available' => 200000, 'withdrawable' => 300000, 'reserved' => 0], $this->balanceOf($user));

        // Certificate funds are spent first.
        $spend = $svc->reserveSpend($user->id, 250000, null);
        $this->assertSame(200000, (int) $spend->certificate_amount);
        $this->assertSame(['available' => 250000, 'certificate_available' => 0, 'withdrawable' => 250000, 'reserved' => 250000], $this->balanceOf($user));
        $svc->reverseSpend($spend, 'test');
        $this->assertSame(500000, $this->balanceOf($user)['available']);
        $this->assertSame(200000, $this->balanceOf($user)['certificate_available']);

        // A reservation never exceeds the balance.
        $all = $svc->reserveSpend($user->id, 900000, null);
        $this->assertSame(500000, (int) $all->amount);
        $this->assertNull($svc->reserveSpend($user->id, 1000, null));
        $svc->confirmSpend($all);
        $this->assertSame('spent', $all->fresh()->status);
        $this->assertSame(['available' => 0, 'certificate_available' => 0, 'withdrawable' => 0, 'reserved' => 0], $this->balanceOf($user));
        $history = StateTransition::where('model_type', 'client_balance_operation')->pluck('to')->countBy()->all();
        $this->assertSame(['credited' => 2, 'spend_reserved' => 2, 'spend_reversed' => 1, 'spent' => 1], array_intersect_key($history, array_flip(['credited', 'spend_reserved', 'spend_reversed', 'spent'])));
        $this->assertCount(2, $this->events('pay.balance.credited'));
    }

    public function test_daily_reconciliation_fixes_a_drifted_materialised_balance(): void
    {
        $user = $this->client();
        app(BalanceService::class)->credit($user->id, 100000, 'admin');
        ClientBalance::whereKey($user->id)->update(['available' => 999]);
        $this->artisan('pay:reconcile-balances')->expectsOutput('Fixed balances: 1')->assertSuccessful();
        $this->assertSame(100000, (int) ClientBalance::findOrFail($user->id)->available);
        $this->assertTrue(AuditLog::where('action', 'balance.reconciled')->exists());
    }

    /** A client with 4 000 ₽ refunded to the balance after paying by card. */
    private function refundedClient(string $card = '4111 1111 1111 1111'): array
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $client = $this->client();
        $this->bindCard($client, $card);
        $session = $this->book($client, $p, '2026-10-05 15:00');
        $this->assertSame('paid', $session->status);
        $this->actAs($client);
        $this->postJson('/api/v1/booking/change-psychologist', ['psychologist_id' => $p->id])->assertOk();
        $this->credit($client, 100000, certificate: true);

        return [$client, $session];
    }

    public function test_withdrawal_refunds_original_payment_and_keeps_certificate_funds(): void
    {
        [$client, $session] = $this->refundedClient();
        $this->postJson('/api/v1/payments/balance/withdraw')->assertCreated()->assertJsonPath('balance.available', 100000);

        $op = ClientBalanceOperation::where('type', 'withdraw')->firstOrFail();
        $this->assertSame('withdrawn', $op->status);
        $this->assertSame(400000, (int) $op->amount);
        $payment = Payment::findOrFail($session->payment_id);
        $this->assertSame('refunded', $payment->status);
        $this->assertSame(1, Receipt::where('payment_id', $payment->id)->where('kind', 'income_return')->count());
        $this->assertSame(['available' => 100000, 'certificate_available' => 100000, 'withdrawable' => 0, 'reserved' => 0], $this->balanceOf($client));
        $this->assertCount(1, $this->events('pay.balance.withdrawal_requested'));

        $this->postJson('/api/v1/payments/balance/withdraw')->assertStatus(422)->assertJsonPath('code', 'nothing_to_withdraw');
    }

    public function test_failed_refund_puts_withdrawal_in_review_and_admin_resolves_it(): void
    {
        [$client] = $this->refundedClient('4000 0000 0000 0077');
        $this->postJson('/api/v1/payments/balance/withdraw')->assertCreated();
        $op = ClientBalanceOperation::where('type', 'withdraw')->firstOrFail();
        $this->assertSame('withdraw_review', $op->status);
        $this->assertSame('failed', PaymentRefund::firstOrFail()->status);
        // The remainder is not reduced until the money is actually returned... but stays reserved.
        $this->assertSame(['available' => 100000, 'certificate_available' => 100000, 'withdrawable' => 0, 'reserved' => 400000], $this->balanceOf($client));

        $this->actingAsRole('client');
        $this->postJson("/api/v1/admin/finance/withdrawals/{$op->id}/resolve", ['action' => 'cancel', 'reason' => 'x'])->assertForbidden();

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/finance/balance-operations?type=withdraw')->assertOk()->assertJsonPath('data.0.status', 'withdraw_review');
        $this->postJson("/api/v1/admin/finance/withdrawals/{$op->id}/resolve", ['action' => 'cancel', 'reason' => 'Банк не принимает возврат, свяжемся с клиентом'])
            ->assertOk()->assertJsonPath('data.status', 'withdraw_cancelled');
        $this->assertSame(500000, $this->balanceOf($client)['available']);
        $this->assertTrue(AuditLog::where('action', 'withdrawal.withdraw_cancelled')->exists());
    }

    public function test_client_payments_summary_and_history_show_balance_cards_and_receipts(): void
    {
        [$client, $session] = $this->refundedClient();
        $summary = $this->getJson('/api/v1/payments/summary')->assertOk();
        $summary->assertJsonPath('data.balance.available', 500000)->assertJsonPath('data.balance.certificate_available', 100000)
            ->assertJsonPath('data.cards.0.card_mask', '•••• 1111');
        $this->getJson('/api/v1/payments/history')->assertOk()
            ->assertJsonPath('data.0.id', $session->payment_id)
            ->assertJsonPath('data.0.receipts.0.calculation_method', 'PREPAYMENT_FULL')
            ->assertJsonPath('data.0.session.discount', 0);
        $this->getJson('/api/v1/payments/balance/operations')->assertOk()->assertJsonFragment(['reason' => 'change_psychologist', 'amount' => 400000]);
        $this->assertSame('cancelled_by_client', TherapySession::findOrFail($session->id)->status);
    }
}
