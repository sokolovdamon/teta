<?php

namespace Tests\Feature\Payouts;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payouts\Listeners\ComplaintRefundedListener;
use App\Modules\Payouts\Listeners\SessionOutcomeListener;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Models\PayeeBalance;
use App\Modules\Payouts\Services\AccrualService;
use App\Support\Events\DomainEvent;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesPsychologists;
use Tests\Concerns\PayoutFixtures;
use Tests\TestCase;

/** ST-06: accruals from session outcomes, reversals by complaint and outcome corrections (DEC-20, DEC-57, Q-56). */
class AccrualTest extends TestCase
{
    use CreatesPsychologists, PayoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Moscow'));
        $this->fakeGateway();
    }

    public function test_held_session_accrues_70_percent_of_full_price_regardless_of_discount_and_certificate(): void
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $session = $this->holdSession($p, attrs: ['discount' => 100000, 'amount_due' => 300000, 'payment_source' => 'mixed']);

        $accrual = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('session', $accrual->kind);
        $this->assertSame('accrued', $accrual->status);
        $this->assertSame(400000, (int) $accrual->base_amount);
        $this->assertSame(30, (int) $accrual->commission_percent);
        $this->assertSame(280000, (int) $accrual->amount);
        $this->assertSame(100000, $accrual->meta['discount']);
        $this->assertSame(280000, (int) PayeeBalance::find($p->user_id)->available);
        $this->assertDatabaseHas('domain_events', ['name' => 'payout.accrual.accrued', 'aggregate_id' => $accrual->id]);
    }

    public function test_commission_comes_from_settings(): void
    {
        Settings::set('P-COMMISSION', 25);
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $session = $this->holdSession($p);

        $this->assertSame(300000, (int) Accrual::where('source_id', $session->id)->value('amount'));
    }

    public function test_corporate_session_accrues_from_psychologist_price(): void
    {
        $p = $this->makePsychologist(['price_individual' => 500000]);
        $client = $this->userWithRole('client');
        $participation = $this->corporateParticipation($client);
        $session = $this->holdSession($p, $client, ['corporate_participation_id' => $participation->id, 'amount_due' => 0, 'payment_source' => 'corporate', 'paid_at' => null]);

        $accrual = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame(350000, (int) $accrual->amount);
        $this->assertTrue($accrual->meta['corporate']);
    }

    public function test_corporate_session_without_fixed_price_uses_current_psychologist_price(): void
    {
        $p = $this->makePsychologist(['price_individual' => 500000]);
        $client = $this->userWithRole('client');
        $participation = $this->corporateParticipation($client);
        $session = $this->holdSession($p, $client, ['corporate_participation_id' => $participation->id, 'price' => 0, 'amount_due' => 0, 'payment_source' => 'corporate']);

        $this->assertSame(350000, (int) Accrual::where('source_id', $session->id)->value('amount'));
    }

    public function test_client_no_show_accrues_70_percent_of_retained_amount(): void
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $session = $this->paidSession($p, status: TherapySession::IN_PROGRESS);
        $session->transitionTo(TherapySession::CLIENT_NO_SHOW, null, 'client did not join', ['outcome_at' => now()]);

        $accrual = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('client_no_show', $accrual->kind);
        $this->assertSame(280000, (int) $accrual->amount);
        $this->assertSame('Клиент не пришёл на сессию, оплата удержана', $accrual->reason);
    }

    public function test_late_cancellation_by_client_after_charge_accrues_retained_share(): void
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $session = $this->paidSession($p);
        $session->transitionTo(TherapySession::CANCELLED_BY_CLIENT, $session->client_id, 'client cancelled', ['cancelled_at' => now()], ['kind' => 'late_cancel', 'amount_charged' => 400000, 'price' => 400000]);

        $accrual = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('late_cancel', $accrual->kind);
        $this->assertSame(280000, (int) $accrual->amount);

        // With a partial refund on late cancellation the psychologist gets 70 % of what was retained.
        Settings::set('P-LATE-CANCEL-REFUND', 50);
        $other = $this->paidSession($p);
        $other->transitionTo(TherapySession::CANCELLED_BY_CLIENT, $other->client_id, 'client cancelled', ['cancelled_at' => now()], ['kind' => 'late_cancel']);
        $this->assertSame(140000, (int) Accrual::where('source_id', $other->id)->value('amount'));
    }

    public function test_no_accrual_for_free_cancellation_change_of_psychologist_and_psychologist_side_outcomes(): void
    {
        $p = $this->makePsychologist();

        $free = $this->paidSession($p, status: TherapySession::BOOKED, attrs: ['paid_at' => null]);
        $free->transitionTo(TherapySession::CANCELLED_BY_CLIENT, $free->client_id, 'free cancellation', [], ['kind' => 'free']);

        $change = $this->paidSession($p);
        $change->transitionTo(TherapySession::CANCELLED_BY_CLIENT, $change->client_id, 'change of psychologist', [], ['kind' => 'change_psychologist']);

        $byPsy = $this->paidSession($p);
        $byPsy->transitionTo(TherapySession::CANCELLED_BY_PSY, $p->user_id, 'psychologist cancelled');

        $noShow = $this->paidSession($p, status: TherapySession::IN_PROGRESS);
        $noShow->transitionTo(TherapySession::PSY_NO_SHOW);

        $tech = $this->paidSession($p, status: TherapySession::IN_PROGRESS);
        $tech->transitionTo(TherapySession::TECH_ISSUE);

        $this->assertSame(0, Accrual::count());
        $this->assertSame(0, (int) (PayeeBalance::find($p->user_id)?->available ?? 0));
    }

    public function test_repeated_event_delivery_is_idempotent(): void
    {
        $p = $this->makePsychologist();
        $session = $this->holdSession($p);
        $event = DomainEvent::where('name', 'book.session.held')->where('aggregate_id', $session->id)->sole();

        app(SessionOutcomeListener::class)->handle($event);
        app(SessionOutcomeListener::class)->handle($event);

        $this->assertSame(1, Accrual::where('source_id', $session->id)->count());
        $this->assertSame(280000, (int) PayeeBalance::find($p->user_id)->available);
    }

    public function test_admin_outcome_corrections_reverse_and_recreate_the_accrual(): void
    {
        $p = $this->makePsychologist();
        $session = $this->holdSession($p);
        $admin = $this->userWithRole('admin');

        // held → tech issue: the accrual is reversed, the psychologist sees why.
        $session->transitionTo(TherapySession::TECH_ISSUE, $admin->id, 'corrected by the session log');
        $accrual = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('reversed', $accrual->status);
        $this->assertSame(280000, (int) $accrual->reversed_amount);
        $this->assertSame('Итог сессии исправлен: техническая проблема', $accrual->reason);
        $this->assertSame(0, (int) PayeeBalance::find($p->user_id)->available);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'payout.accrual_adjusted']);

        // tech issue → held again: a new accrual.
        $session->fresh()->transitionTo(TherapySession::HELD, $admin->id, 'corrected back');
        $this->assertSame(2, Accrual::where('source_id', $session->id)->count());
        $this->assertSame(1, Accrual::where('source_id', $session->id)->where('status', 'accrued')->count());
        $this->assertSame(280000, (int) PayeeBalance::find($p->user_id)->available);

        // held → client no-show keeps the same amount: relabelled, no new money.
        $session->fresh()->transitionTo(TherapySession::CLIENT_NO_SHOW, $admin->id, 'client did not join');
        $live = Accrual::where('source_id', $session->id)->where('status', 'accrued')->sole();
        $this->assertSame('client_no_show', $live->kind);
        $this->assertSame(280000, (int) PayeeBalance::find($p->user_id)->available);
    }

    public function test_partial_and_full_complaint_reversal_of_an_unpaid_accrual(): void
    {
        $p = $this->makePsychologist();
        $session = $this->holdSession($p);

        $complaint = $this->refundComplaint($session, 50);
        $accrual = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('accrued', $accrual->status);
        $this->assertSame(140000, (int) $accrual->reversed_amount);
        $this->assertSame(140000, $accrual->net());
        $this->assertStringContainsString('50 %', $accrual->reason);
        $this->assertSame(140000, (int) PayeeBalance::find($p->user_id)->available);

        // The same complaint delivered again does not reverse twice.
        $event = DomainEvent::where('name', 'pay.complaint.refunded')->where('aggregate_id', $complaint->id)->sole();
        app(ComplaintRefundedListener::class)->handle($event);
        $this->assertSame(140000, (int) $accrual->fresh()->reversed_amount);

        // Another session refunded in full → reversed.
        $other = $this->holdSession($p);
        $this->refundComplaint($other, 100);
        $this->assertSame('reversed', Accrual::where('source_id', $other->id)->value('status'));
        $this->assertSame(140000, (int) PayeeBalance::find($p->user_id)->available);
    }

    public function test_complaint_share_is_derived_from_refund_amount_when_share_is_missing(): void
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $session = $this->holdSession($p, attrs: ['discount' => 100000, 'amount_due' => 300000]);
        $complaint = new ChargeComplaint([
            'therapy_session_id' => $session->id, 'client_id' => $session->client_id, 'reason' => 'Связь пропадала',
            'due_date' => now()->addDays(20)->toDateString(), 'refund_amount' => 75000,
        ]);
        $complaint->forceFill(['status' => 'approved'])->save();
        $complaint->transitionTo('refunded', null, 'complaint approved', [], ['session_id' => $session->id, 'refund_amount' => 75000]);

        // 75 000 of the 300 000 paid = 25 % → 25 % of the 280 000 accrual.
        $this->assertSame(70000, (int) Accrual::where('source_id', $session->id)->value('reversed_amount'));
    }

    public function test_supervision_accrual_uses_supervision_commission_and_is_idempotent(): void
    {
        $supervisor = $this->userWithRole('psychologist', 'supervisor');
        $meeting = $this->paidSession($this->makePsychologist());
        $service = app(AccrualService::class);

        $first = $service->accrueSupervision($meeting, $supervisor, 300000);
        $again = $service->accrueSupervision($meeting, $supervisor, 300000);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('supervision', $first->kind);
        $this->assertSame(210000, (int) $first->amount);
        $this->assertSame(210000, (int) PayeeBalance::find($supervisor->id)->available);

        Settings::set('P-SUPERV-COMMISSION', 20);
        $second = $service->accrueSupervision($this->paidSession($this->makePsychologist()), $supervisor, 300000);
        $this->assertSame(240000, (int) $second->amount);
        $this->assertSame(20, (int) $second->commission_percent);

        $service->reverseSupervision($meeting, $supervisor, 'Супервизия отменена');
        $this->assertSame('reversed', $first->fresh()->status);
        $this->assertSame(240000, (int) PayeeBalance::find($supervisor->id)->available);
    }
}
