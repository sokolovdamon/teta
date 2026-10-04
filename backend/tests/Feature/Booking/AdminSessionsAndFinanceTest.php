<?php

namespace Tests\Feature\Booking;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Psychologists\Services\QualificationService;
use App\Modules\Psychologists\Services\WorkStatusService;
use Tests\TestCase;

/** ADM-04 sessions, ADM-07 finance, platform cancellations on blocking (BR-CANC-09), PAYOUT contract. */
class AdminSessionsAndFinanceTest extends TestCase
{
    use BookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    private function paidSession(string $at = '2026-10-08 14:00'): TherapySession
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $client = $this->client();
        $this->bindCard($client);
        $s = $this->book($client, $p, $at);
        $this->moveTo('2026-10-08 02:00');
        $this->artisan('pay:dispatch-due-charges');

        return $s->fresh();
    }

    public function test_admin_session_list_card_outcome_correction_and_cancel(): void
    {
        $s = $this->paidSession();
        $this->actingAsRole('client');
        $this->getJson('/api/v1/admin/sessions')->assertForbidden();

        $admin = $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/sessions?status=paid')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $s->id)
            ->assertJsonMissingPath('data.0.client_requests');
        $this->getJson('/api/v1/admin/sessions?q='.urlencode($s->client->email))->assertOk()->assertJsonPath('meta.total', 1);
        $card = $this->getJson("/api/v1/admin/sessions/{$s->id}")->assertOk();
        $card->assertJsonPath('charge_task.status', 'succeeded')->assertJsonPath('payments.0.status', 'succeeded');
        $this->assertContains('book.session.paid', collect($card->json('history'))->pluck('event')->all());
        $this->assertContains('pay.charge.succeeded', collect($card->json('history'))->pluck('event')->all());

        // Outcome after the session by the log.
        $this->moveTo('2026-10-08 15:30');
        $s->forceFill(['psychologist_joined_at' => $this->msk('2026-10-08 13:59'), 'client_joined_at' => null])->save();
        $this->postJson("/api/v1/admin/sessions/{$s->id}/outcome", ['outcome' => 'client_no_show'])->assertStatus(422);
        $this->postJson("/api/v1/admin/sessions/{$s->id}/outcome", ['outcome' => 'client_no_show', 'reason' => 'По журналу клиент не подключился'])
            ->assertOk()->assertJsonPath('data.status', 'client_no_show');
        $this->postJson("/api/v1/admin/sessions/{$s->id}/outcome", ['outcome' => 'psy_no_show', 'reason' => 'Исправление: психолог тоже не подключился'])
            ->assertOk()->assertJsonPath('data.client_choice', 'pending');
        $this->assertSame(2, AuditLog::where('action', 'session.outcome_set')->count());
        // A finished session can not be cancelled by the platform.
        $this->postJson("/api/v1/admin/sessions/{$s->id}/cancel", ['reason' => 'Технические работы'])->assertStatus(409);
        $this->assertNotNull($admin);
    }

    public function test_platform_cancel_refunds_paid_session_to_balance(): void
    {
        $s = $this->paidSession();
        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/sessions/{$s->id}/cancel", ['reason' => 'Психолог покинул платформу'])->assertOk()->assertJsonPath('data.status', 'cancelled_by_system');
        $this->assertSame(400000, $this->balanceOf($s->client)['available']);
        $this->assertTrue(AuditLog::where('action', 'session.cancelled')->exists());
        $this->assertSame('admin', $this->events('book.session.cancelled_by_system')->first()->payload['kind']);
    }

    public function test_blocking_a_psychologist_cancels_upcoming_sessions_with_refund(): void
    {
        $s = $this->paidSession();
        $client = $s->client;
        $booked = $this->book($client, $s->psychologist, '2026-10-12 14:00');
        $this->actingAsRole('super_admin');
        $this->postJson("/api/v1/admin/users/{$s->psychologist->user_id}/block", ['reason' => 'Нарушение правил'])->assertOk();

        $this->assertSame('cancelled_by_system', $s->fresh()->status);
        $this->assertSame('cancelled_by_system', $booked->fresh()->status);
        $this->assertSame(400000, $this->balanceOf($client)['available']);
        $this->assertSame(['block'], $this->events('book.session.cancelled_by_system')->pluck('payload.kind')->unique()->values()->all());
    }

    public function test_revoked_qualification_and_blocked_work_status_cancel_upcoming_sessions(): void
    {
        $admin = $this->userWithRole('super_admin');
        $p1 = $this->makePsychologist(['price_individual' => 400000]);
        $p2 = $this->makePsychologist(['price_individual' => 400000]);
        $client = $this->client();
        $this->bindCard($client);
        $s1 = $this->book($client, $p1, '2026-10-08 14:00');
        $s2 = $this->book($client, $p2, '2026-10-09 14:00');

        app(QualificationService::class)->reject($p1, $admin, 'Диплом не подтверждён');
        app(WorkStatusService::class)->block($p2, $admin, 'Нарушение правил платформы');

        $this->assertSame('cancelled_by_system', $s1->fresh()->status);
        $this->assertSame('cancelled_by_system', $s2->fresh()->status);
        $this->assertSame('cancelled', ChargeTask::where('therapy_session_id', $s1->id)->value('status'));
        $this->assertTrue(UserNotification::where('user_id', $client->id)->where('template_code', 'book.session_cancelled_platform')->exists());
    }

    public function test_finance_summary_payments_manual_refund_and_charge_tasks(): void
    {
        $s = $this->paidSession();
        $this->moveTo('2026-10-08 14:00');
        $this->artisan('book:advance');
        $s->forceFill(['joint_duration_sec' => 3000])->save();
        $this->moveTo('2026-10-09 14:01');
        $this->artisan('book:advance');
        $this->assertSame('held', $s->fresh()->status);

        $this->actingAsRole('client');
        $this->getJson('/api/v1/admin/finance/summary')->assertForbidden();

        $this->actingAsRole('admin');
        $summary = $this->getJson('/api/v1/admin/finance/summary?from=2026-10-01&to=2026-10-31')->assertOk();
        $summary->assertJsonPath('data.turnover_total', 400000)
            ->assertJsonPath('data.sessions.retained', 400000)
            ->assertJsonPath('data.sessions.psychologist_share', 280000)
            ->assertJsonPath('data.sessions.platform_commission', 120000);

        $payment = Payment::firstOrFail();
        $this->getJson('/api/v1/admin/finance/payments?status=succeeded')->assertOk()->assertJsonPath('data.0.id', $payment->id)->assertJsonPath('data.0.user.email', $s->client->email);
        $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refund", ['amount' => 500000, 'reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refund", ['amount' => 100000, 'reason' => 'Компенсация за сбой связи'])
            ->assertOk()->assertJsonPath('data.status', 'partially_refunded')->assertJsonPath('refund_status', 'succeeded');
        $this->assertTrue(AuditLog::where('action', 'payment.refund')->exists());
        $this->assertSame(300000, $s->fresh()->retainedAmount());
        $this->getJson('/api/v1/admin/finance/refunds')->assertOk()->assertJsonPath('data.0.amount', 100000);
        $this->getJson('/api/v1/admin/finance/charge-tasks?status=succeeded')->assertOk()->assertJsonPath('data.0.status', 'succeeded');
    }

    public function test_held_session_accrues_70_percent_of_full_price_in_payouts(): void
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $client = $this->client();
        $this->bindCard($client);
        $this->credit($client, 100000, certificate: true);
        $s = $this->book($client, $p, '2026-10-08 14:00');
        $this->moveTo('2026-10-08 02:00');
        $this->artisan('pay:dispatch-due-charges');
        $this->moveTo('2026-10-08 14:00');
        $this->artisan('book:advance');
        $this->asPsychologistOf($p);
        $s->forceFill(['joint_duration_sec' => 2900])->save();
        $this->postJson("/api/v1/booking/pro/sessions/{$s->id}/outcome", ['outcome' => 'held'])->assertOk();

        $accrual = Accrual::where('source_id', $s->id)->firstOrFail();
        $this->assertSame(280000, (int) $accrual->amount);
        $this->assertSame($p->user_id, $accrual->user_id);
    }

    private function asPsychologistOf($p): User
    {
        return $this->actAs(User::findOrFail($p->user_id));
    }
}
