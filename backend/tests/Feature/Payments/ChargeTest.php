<?php

namespace Tests\Feature\Payments;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Payments\Gateway\Emulator\EmulatorCheckout;
use App\Modules\Payments\Gateway\Emulator\EmulatorOperation;
use App\Modules\Payments\Gateway\Emulator\EmulatorWebhooks;
use App\Modules\Payments\Models\ChargeAttempt;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\Receipt;
use App\Modules\Payments\Models\WebhookInbox;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Payments\Services\PaymentService;
use App\Support\StateMachine\StateTransition;
use Tests\Feature\Booking\BookingFixtures;
use Tests\TestCase;

/** ST-02 (charge task) and ST-03 (payment), SEQ-02. */
class ChargeTest extends TestCase
{
    use BookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    /** Session on Thursday 14:00 MSK: due Thu 02:00, deadline Thu 12:00, retries +2 h, +6 h, +9 h after the first attempt. */
    private function bookedSession(string $card = '4111 1111 1111 1111', int $price = 400000, int $balance = 0): TherapySession
    {
        $p = $this->makePsychologist(['price_individual' => $price]);
        $client = $this->client();
        $this->bindCard($client, $card);
        if ($balance) {
            $this->credit($client, $balance);
        }

        return $this->book($client, $p, '2026-10-08 14:00');
    }

    private function dispatch(): void
    {
        $this->artisan('pay:dispatch-due-charges')->assertSuccessful();
    }

    public function test_due_charge_spends_balance_first_then_card_and_marks_session_paid(): void
    {
        $session = $this->bookedSession(balance: 150000);
        $this->moveTo('2026-10-08 01:59');
        $this->dispatch();
        $this->assertSame('scheduled', ChargeTask::firstOrFail()->status);

        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $task = ChargeTask::firstOrFail();
        $this->assertSame('succeeded', $task->status);
        $this->assertSame(150000, (int) $task->balance_part);
        $payment = Payment::findOrFail($task->payment_id);
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame(250000, (int) $payment->amount);
        $this->assertSame($task->id.':1', $payment->idempotency_key);
        $receipt = Receipt::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('PREPAYMENT_FULL', $receipt->calculation_method);
        $this->assertSame('registered', $receipt->status);

        $session->refresh();
        $this->assertSame('paid', $session->status);
        $this->assertSame('mixed', $session->payment_source);
        $this->assertSame(400000, (int) $session->amount_charged);
        $this->assertSame('spent', ClientBalanceOperation::where('type', 'spend')->value('status'));
        $this->assertCount(1, $this->events('book.session.paid'));
        $this->assertSame(400000, $this->events('book.session.paid')->first()->payload['amount_charged']);
        $this->assertTrue(UserNotification::where('user_id', $session->client_id)->where('template_code', 'pay.charge_succeeded')->exists());
        $this->assertSame(['scheduled', 'in_progress', 'succeeded'], StateTransition::where('model_id', $task->id)->orderBy('created_at')->pluck('to')->all());

        // The webhook of the same operation arrives later: nothing changes (BR-PAY-06).
        $op = EmulatorOperation::where('idempotency_key', $payment->idempotency_key)->firstOrFail();
        [$status, $body] = app(EmulatorWebhooks::class)->deliver($raw = (string) json_encode(EmulatorWebhooks::payload($op, 'payment.succeeded')), EmulatorWebhooks::sign($raw));
        $this->assertSame(200, $status);
        $this->assertTrue($body['duplicate'] ?? false);
        $this->assertSame(1, Payment::where('purpose', 'session')->count());
        $this->assertSame(1, WebhookInbox::where('event_type', 'payment.succeeded')->count());
    }

    public function test_balance_covering_the_whole_amount_needs_no_card_payment(): void
    {
        $session = $this->bookedSession(balance: 500000);
        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $this->assertSame('succeeded', ChargeTask::firstOrFail()->status);
        $this->assertSame('balance', $session->fresh()->payment_source);
        $this->assertSame(0, Payment::count());
        $this->assertSame(100000, $this->balanceOf($session->client)['available']);
    }

    public function test_retryable_decline_is_retried_by_schedule_and_succeeds_after_card_change(): void
    {
        $session = $this->bookedSession('4000 0000 0000 9995');
        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $task = ChargeTask::firstOrFail();
        $this->assertSame('retry_wait', $task->status);
        $this->assertSame('retry', $task->last_error_category);
        $this->assertTrue($task->next_attempt_at->equalTo($this->msk('2026-10-08 04:00')));
        $this->assertTrue(UserNotification::where('user_id', $session->client_id)->where('template_code', 'pay.charge_failed')->exists());

        $this->moveTo('2026-10-08 04:00');
        $this->dispatch();
        $task->refresh();
        $this->assertSame(2, $task->attempts);
        $this->assertTrue($task->next_attempt_at->equalTo($this->msk('2026-10-08 08:00')));

        // The client binds a working card: the waiting charge goes on with it.
        PaymentMethod::where('user_id', $session->client_id)->update(['status' => 'removed']);
        $this->bindCard($session->client, '4111 1111 1111 1111');
        $this->moveTo('2026-10-08 08:00');
        $this->dispatch();
        $this->assertSame('succeeded', $task->fresh()->status);
        $this->assertSame('paid', $session->fresh()->status);
        $this->assertSame(3, ChargeAttempt::where('charge_task_id', $task->id)->count());
        $this->assertSame(['declined', 'declined', 'succeeded'], ChargeAttempt::where('charge_task_id', $task->id)->orderBy('number')->pluck('result')->all());
    }

    public function test_no_retry_decline_waits_for_payment_with_another_card_by_the_payer(): void
    {
        $session = $this->bookedSession('4000 0000 0000 0002');
        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $task = ChargeTask::firstOrFail();
        $this->assertSame('retry_wait', $task->status);
        $this->assertNull($task->next_attempt_at);

        $this->actAs($session->client);
        $this->getJson('/api/v1/payments/summary')->assertOk()->assertJsonPath('data.charges.0.can_pay', true);
        $res = $this->postJson("/api/v1/payments/charges/{$task->id}/pay")->assertOk();
        $url = $res->json('confirmation_url');
        $this->assertNotNull($url);
        // While the payer is in the checkout, automatic retries do not run.
        $task->forceFill(['next_attempt_at' => now()])->save();
        $this->dispatch();
        $this->assertSame(1, (int) $task->fresh()->attempts);

        app(EmulatorCheckout::class)->submitCard(EmulatorOperation::findOrFail(basename($url)), ['card_number' => '2200000000000004', 'exp_month' => 3, 'exp_year' => 2032, 'cvc' => '111']);
        $this->assertSame('succeeded', $task->fresh()->status);
        $this->assertSame('paid', $session->fresh()->status);
        $this->getJson("/api/v1/payments/status/{$res->json('payment_id')}")->assertOk()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_charge_deadline_cancels_session_without_retention_and_reverses_balance(): void
    {
        $session = $this->bookedSession('4000 0000 0000 0069', balance: 100000);
        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $this->assertSame('retry_wait', ChargeTask::firstOrFail()->status);
        $this->assertSame(0, $this->balanceOf($session->client)['available']);

        $this->moveTo('2026-10-08 12:00');
        $this->dispatch();
        $task = ChargeTask::firstOrFail();
        $this->assertSame('failed_final', $task->status);
        $session->refresh();
        $this->assertSame('cancelled_by_system', $session->status);
        $this->assertSame('charge_failed', $session->cancel_kind);
        $this->assertSame('spend_reversed', ClientBalanceOperation::where('type', 'spend')->value('status'));
        $this->assertSame(100000, $this->balanceOf($session->client)['available']);
        $event = $this->events('pay.charge.failed_final')->first();
        $this->assertSame($session->id, $event->payload['session_id']);
        $this->assertTrue(UserNotification::where('user_id', $session->client_id)->where('template_code', 'book.session_cancelled_unpaid')->exists());
    }

    public function test_unknown_result_is_resolved_by_status_query_without_a_second_charge(): void
    {
        // 4 000,13 ₽: the emulator "times out" and loses the webhook, but the charge went through.
        $session = $this->bookedSession(price: 400013);
        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $task = ChargeTask::firstOrFail();
        $this->assertSame('retry_wait', $task->status);
        $this->assertSame('unknown', $task->last_error_category);
        $this->assertSame('unknown', Payment::firstOrFail()->status);

        $this->moveTo('2026-10-08 02:10');
        $this->dispatch();
        $this->assertSame('succeeded', $task->fresh()->status);
        $this->assertSame('paid', $session->fresh()->status);
        $this->assertSame(1, EmulatorOperation::where('kind', 'charge')->count());
        $this->assertSame(1, Payment::count());
        $this->assertSame('succeeded', Payment::firstOrFail()->status);
    }

    public function test_stuck_attempt_is_recovered_by_status_query(): void
    {
        $session = $this->bookedSession();
        $this->moveTo('2026-10-08 02:00');
        // Simulate a crash after the payment row was created and the gateway charged, before the result was applied.
        $task = ChargeTask::firstOrFail();
        $task->transitionTo('in_progress', attributes: ['attempts' => 1, 'locked_at' => now()]);
        $payment = app(PaymentService::class)->createPayment($session->client, 'session', $session, 400000, 'Оплата', null, ['charge_task_id' => $task->id], false, null, $task->id.':1');
        $task->forceFill(['payment_id' => $payment->id])->save();
        EmulatorOperation::create(['idempotency_key' => $task->id.':1', 'kind' => 'charge', 'status' => 'succeeded', 'amount' => 400000, 'webhook_suppressed' => true]);

        $this->moveTo('2026-10-08 02:11');
        $this->artisan('pay:recover-stuck-charges')->assertSuccessful();
        $this->assertSame('succeeded', $task->fresh()->status);
        $this->assertSame('paid', $session->fresh()->status);
    }

    public function test_without_card_the_task_waits_and_binding_a_card_resumes_it(): void
    {
        $p = $this->makePsychologist();
        $client = $this->client();
        $card = $this->bindCard($client);
        $session = $this->book($client, $p, '2026-10-08 14:00');
        $this->actAs($client);
        $this->deleteJson("/api/v1/payments/cards/{$card->id}")->assertOk()->assertJsonPath('unpaid_sessions', 1)->assertJsonPath('has_other_card', false);
        $this->assertTrue(UserNotification::where('user_id', $client->id)->where('template_code', 'pay.card_removed_unpaid')->exists());
        $this->assertSame('removed', $card->fresh()->token);

        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $task = ChargeTask::firstOrFail();
        $this->assertSame('retry_wait', $task->status);
        $this->assertSame('no_card', $task->last_error_code);
        $this->assertTrue(UserNotification::where('user_id', $client->id)->where('template_code', 'pay.charge_no_card')->exists());

        $this->bindCard($client);
        $this->dispatch();
        $this->assertSame('succeeded', $task->fresh()->status);
        $this->assertSame('paid', $session->fresh()->status);
    }

    public function test_cancel_before_charge_cancels_task_and_payer_payment_after_cancel_is_refunded(): void
    {
        $session = $this->bookedSession('4000 0000 0000 0002', balance: 100000);
        $this->moveTo('2026-10-08 02:00');
        $this->dispatch();
        $task = ChargeTask::firstOrFail();
        $this->actAs($session->client);
        $url = $this->postJson("/api/v1/payments/charges/{$task->id}/pay")->assertOk()->json('confirmation_url');

        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel")->assertOk()->assertJsonPath('kind', 'free_cancel');
        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertSame(100000, $this->balanceOf($session->client)['available']);

        // The payer finishes the checkout after the cancellation: the money goes straight back to the card.
        app(EmulatorCheckout::class)->submitCard(EmulatorOperation::findOrFail(basename($url)), ['card_number' => '4111111111111111', 'exp_month' => 3, 'exp_year' => 2032, 'cvc' => '111']);
        $payment = Payment::where('with_payer', true)->firstOrFail();
        $this->assertSame('refunded', $payment->status);
        $this->assertSame('cancelled_by_client', $session->fresh()->status);
    }

    public function test_dispatcher_is_idempotent_for_the_same_attempt_key(): void
    {
        $this->bookedSession();
        $this->moveTo('2026-10-08 02:00');
        $task = ChargeTask::firstOrFail();
        app(ChargeService::class)->attempt($task->id);
        app(ChargeService::class)->attempt($task->id);
        $this->dispatch();
        $this->assertSame(1, EmulatorOperation::where('kind', 'charge')->count());
        $this->assertSame(1, Payment::count());
    }
}
