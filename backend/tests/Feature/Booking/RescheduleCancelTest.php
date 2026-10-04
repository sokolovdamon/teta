<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Support\Settings\Settings;
use App\Support\StateMachine\StateTransition;
use Tests\TestCase;

/** SEQ-03 / SEQ-04: reschedule before and after the charge (DEC-56), client cancellations, change of psychologist (DEC-48). */
class RescheduleCancelTest extends TestCase
{
    use BookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    private function paidSession(string $mskStart = '2026-10-08 14:00'): TherapySession
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $client = $this->client();
        $this->bindCard($client);
        $session = $this->book($client, $p, $mskStart);
        $this->moveTo('2026-10-08 02:00');
        $this->artisan('pay:dispatch-due-charges');
        $this->assertSame('paid', $session->fresh()->status);

        return $session->fresh();
    }

    public function test_reschedule_before_charge_recomputes_the_charge_task_in_the_same_transaction(): void
    {
        $p = $this->makePsychologist();
        $client = $this->actAs($this->client());
        $this->bindCard($client);
        $session = $this->book($client, $p, '2026-10-08 14:00');

        $slots = $this->getJson("/api/v1/booking/sessions/{$session->id}/reschedule-slots")->assertOk();
        $this->assertFalse($slots->json('late'));
        $this->assertContains($this->msk('2026-10-08 14:00')->utc()->toIso8601String(), $slots->json('data'));

        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-10 11:00')->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'booked');
        $session->refresh();
        $this->assertTrue($session->starts_at->equalTo($this->msk('2026-10-10 11:00')));
        $task = ChargeTask::firstOrFail();
        $this->assertSame('scheduled', $task->status);
        $this->assertTrue($task->due_at->equalTo($this->msk('2026-10-09 23:00')));
        $this->assertTrue($task->deadline_at->equalTo($this->msk('2026-10-10 09:00')));
        $event = $this->events('book.session.rescheduled')->first();
        $this->assertSame('reschedule', $event->payload['kind']);
        $this->assertSame('booked', $event->payload['to']);
        $this->assertSame(1, StateTransition::where('model_id', $session->id)->where('event', 'book.session.rescheduled')->count());
        $this->assertTrue(UserNotification::where('user_id', $p->user_id)->where('template_code', 'book.psy_session_rescheduled')->exists());

        // Unlimited before the charge.
        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-11 11:00')->toIso8601String()])->assertOk();
        // Occupied or unavailable time is refused.
        $other = $this->client();
        $this->bindCard($other);
        $this->book($other, $p, '2026-10-12 11:00');
        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-12 11:00')->toIso8601String()])->assertStatus(422);
    }

    public function test_reschedule_to_a_time_closer_than_charge_offset_charges_right_away(): void
    {
        $p = $this->makePsychologist();
        $client = $this->client();
        $this->bindCard($client);
        $session = $this->book($client, $p, '2026-10-08 14:00');
        $this->actAs($client);
        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-05 15:00')->toIso8601String()])->assertOk();
        $this->assertTrue(ChargeTask::firstOrFail()->due_at->lessThanOrEqualTo(now()));
        $this->artisan('pay:dispatch-due-charges');
        $this->assertSame('paid', $session->fresh()->status);
    }

    public function test_reschedule_after_charge_needs_12_hours_and_respects_the_limit(): void
    {
        $session = $this->paidSession();
        $client = $this->actAs($session->client);

        $slots = $this->getJson("/api/v1/booking/sessions/{$session->id}/reschedule-slots")->assertOk();
        $this->assertTrue($slots->json('late'));
        $this->assertSame(1, $slots->json('late_reschedules_left'));
        foreach ($slots->json('data') as $slot) {
            $this->assertGreaterThanOrEqual($this->msk('2026-10-08 14:00')->timestamp, strtotime($slot));
        }

        // Now 02:00 MSK: 13:00 is less than 12 hours away.
        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-08 13:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'late_reschedule_too_soon');

        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-09 15:00')->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, ChargeTask::count());
        $this->assertSame('succeeded', ChargeTask::firstOrFail()->status);
        $this->assertSame('late_reschedule', $this->events('book.session.rescheduled')->last()->payload['kind']);
        $this->assertSame(400000, (int) $session->fresh()->amount_charged);

        Settings::set('P-LATE-RESCHEDULE-LIMIT', 5);
        // The limit in force at booking applies (parameters are snapshotted in the session).
        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-10 15:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'late_reschedule_limit');
        $this->assertNotNull($client);
    }

    public function test_free_cancel_before_charge(): void
    {
        $p = $this->makePsychologist();
        $client = $this->actAs($this->client());
        $this->bindCard($client);
        $session = $this->book($client, $p, '2026-10-08 14:00');

        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel", ['reason' => 'Не получается'])->assertOk()
            ->assertJsonPath('kind', 'free_cancel')->assertJsonPath('data.status', 'cancelled_by_client');
        $this->assertSame('cancelled', ChargeTask::firstOrFail()->status);
        $event = $this->events('book.session.cancelled_by_client')->first();
        $this->assertSame('free_cancel', $event->payload['kind']);
        $this->assertTrue(UserNotification::where('user_id', $p->user_id)->where('template_code', 'book.psy_session_cancelled')->exists());
        // The slot is free again.
        $other = $this->client();
        $this->bindCard($other);
        $this->assertSame('booked', $this->book($other, $p, '2026-10-08 14:00')->status);
    }

    public function test_cancel_after_charge_requires_confirmation_and_retains_payment(): void
    {
        $session = $this->paidSession();
        $this->actAs($session->client);
        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'late_cancel_confirmation');
        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel", ['confirm_late' => true])->assertOk()
            ->assertJsonPath('kind', 'late_cancel')->assertJsonPath('refund_amount', 0)->assertJsonPath('retained_amount', 400000);

        $event = $this->events('book.session.cancelled_by_client')->first();
        $this->assertSame('late_cancel', $event->payload['kind']);
        $this->assertSame(400000, $event->payload['amount_charged']);
        $this->assertSame(400000, $event->payload['price']);
        $this->assertSame(0, $this->balanceOf($session->client)['available']);
        $this->assertSame(0, ClientBalanceOperation::where('type', 'credit')->count());
    }

    public function test_started_session_can_not_be_cancelled_or_rescheduled(): void
    {
        $session = $this->paidSession();
        $this->moveTo('2026-10-08 14:01');
        $this->actAs($session->client);
        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel", ['confirm_late' => true])->assertStatus(409)->assertJsonPath('code', 'already_started');
        $this->postJson("/api/v1/booking/sessions/{$session->id}/reschedule", ['starts_at' => $this->msk('2026-10-10 15:00')->toIso8601String()])->assertStatus(409);
    }

    public function test_change_of_psychologist_cancels_not_held_sessions_and_credits_paid_ones(): void
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $other = $this->makePsychologist();
        $client = $this->client();
        $this->bindCard($client);
        $paid = $this->book($client, $p, '2026-10-08 14:00');
        $booked = $this->book($client, $p, '2026-10-12 14:00');
        $keep = $this->book($client, $other, '2026-10-12 16:00');
        $this->moveTo('2026-10-08 02:00');
        $this->artisan('pay:dispatch-due-charges');
        $this->assertSame('paid', $paid->fresh()->status);

        $this->actAs($client);
        $this->getJson('/api/v1/booking/my-psychologists')->assertOk()
            ->assertJsonFragment(['psychologist_id' => $p->id, 'upcoming_sessions' => 2, 'slug' => $p->slug]);

        // Less than 12 hours before the paid session: DEC-48 still credits the whole payment.
        $this->postJson('/api/v1/booking/change-psychologist', ['psychologist_id' => $p->id, 'reason' => 'Не подошёл стиль'])
            ->assertOk()->assertExactJson(['cancelled' => 2, 'credited_amount' => 400000]);

        $this->assertSame('cancelled_by_client', $paid->fresh()->status);
        $this->assertSame('change_psychologist', $paid->fresh()->cancel_kind);
        $this->assertSame('cancelled_by_client', $booked->fresh()->status);
        $this->assertSame('booked', $keep->fresh()->status);
        $this->assertSame(400000, $this->balanceOf($client)['available']);
        $credit = ClientBalanceOperation::where('type', 'credit')->firstOrFail();
        $this->assertSame('change_psychologist', $credit->reason);
        $kinds = $this->events('book.session.cancelled_by_client')->pluck('payload.kind')->unique()->values()->all();
        $this->assertSame(['change_psychologist'], $kinds);
        $changed = $this->events('book.psychologist.changed')->first();
        $this->assertSame(['client_id' => $client->id, 'psychologist_id' => $p->id], array_intersect_key($changed->payload, array_flip(['client_id', 'psychologist_id'])));
        $this->assertArrayHasKey('at', $changed->payload);

        // The balance is spent first at the next booking (Q-52).
        $next = $this->book($client, $other, '2026-10-09 12:00');
        $this->moveTo('2026-10-09 00:00');
        $this->artisan('pay:dispatch-due-charges');
        $this->assertSame('balance', $next->fresh()->payment_source);
    }
}
