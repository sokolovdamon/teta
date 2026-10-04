<?php

namespace Tests\Feature\Payouts;

use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Models\PayeeBalance;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Models\PayoutRegistry;
use App\Modules\Payouts\Services\AccrualService;
use App\Modules\Payouts\Services\PayeeBalanceService;
use App\Modules\Payouts\Services\PayoutRegistryService;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesPsychologists;
use Tests\Concerns\PayoutFixtures;
use Tests\TestCase;

/** SEQ-08, ST-07: weekly registry, conditions, sending, webhooks, unknown status, corrections after payout. */
class PayoutRegistryTest extends TestCase
{
    use CreatesPsychologists, PayoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Moscow'));
        $this->fakeGateway();
    }

    private function eligiblePsychologist(int $price = 400000)
    {
        $p = $this->makePsychologist(['price_individual' => $price]);
        $this->supervisionMet($p);
        $this->payoutCard($p->user);

        return $p;
    }

    private function buildOnMonday(?string $from = null): PayoutRegistry
    {
        $this->travelTo($this->nextMonday($from ?? 'now'));
        $this->artisan('payout:build-weekly-registry')->assertSuccessful();

        return PayoutRegistry::orderByDesc('period_start')->firstOrFail();
    }

    public function test_weekly_registry_is_built_sent_and_paid_once(): void
    {
        $p = $this->eligiblePsychologist();
        $this->holdSession($p);
        $this->holdSession($p);

        $registry = $this->buildOnMonday();
        $this->assertSame('2026-10-05', $registry->period_start->toDateString());
        $this->assertSame('2026-10-11', $registry->period_end->toDateString());
        $this->assertTrue($registry->auto_approve);

        $payout = Payout::sole();
        $this->assertSame('sent', $payout->status);
        $this->assertSame(560000, (int) $payout->amount);
        $this->assertSame($payout->id, $payout->idempotency_key);
        $this->assertArrayHasKey($payout->id, $this->gateway->payouts);
        $this->assertSame('tok_payout_0001', $this->gateway->payouts[$payout->id]->token);
        $this->assertSame(560000, $this->gateway->payouts[$payout->id]->amount);
        $this->assertSame(2, Accrual::where('payout_id', $payout->id)->where('status', 'in_registry')->count());
        $balance = PayeeBalance::find($p->user_id);
        $this->assertSame(0, (int) $balance->available);
        $this->assertSame(560000, (int) $balance->in_payout);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'payout.sent']);
        $this->assertSame('sent', $registry->fresh()->status);

        $this->payoutWebhook($payout, 'payout.paid');
        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertNotNull($payout->paid_at);
        $this->assertSame(2, Accrual::where('payout_id', $payout->id)->where('status', 'paid')->count());
        $balance->refresh();
        $this->assertSame(0, (int) $balance->in_payout);
        $this->assertSame(560000, (int) $balance->paid_total);
        $this->assertSame('completed', $registry->fresh()->status);
        $this->assertDatabaseHas('domain_events', ['name' => 'payout.paid', 'aggregate_id' => $payout->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'payout.paid']);

        // No double payout: repeated webhook, repeated run, repeated sending.
        $this->payoutWebhook($payout, 'payout.paid');
        $this->artisan('payout:build-weekly-registry')->assertSuccessful();
        app(PayoutRegistryService::class)->sendRegistry($registry->fresh());
        app(PayoutRegistryService::class)->sendPayout($payout->id);
        $this->assertSame(1, PayoutRegistry::count());
        $this->assertSame(1, Payout::count());
        $this->assertSame(1, $this->gateway->payoutCalls);
        $this->assertSame(560000, (int) $balance->fresh()->paid_total);
    }

    public function test_registry_is_built_only_on_the_payout_day_and_takes_accruals_up_to_sunday(): void
    {
        $p = $this->eligiblePsychologist();
        $this->holdSession($p);

        $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Moscow'));
        $this->artisan('payout:build-weekly-registry')->expectsOutput('Not a payout day.')->assertSuccessful();
        $this->assertSame(0, PayoutRegistry::count());

        // A session held on Monday night belongs to the next week.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 01:00', 'Europe/Moscow'));
        $this->holdSession($p);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00', 'Europe/Moscow'));
        $this->artisan('payout:build-weekly-registry')->assertSuccessful();
        $this->assertSame(280000, (int) Payout::sole()->amount);
        $this->assertSame(280000, (int) PayeeBalance::find($p->user_id)->available);
    }

    public function test_payout_is_blocked_until_the_monthly_supervision_is_met(): void
    {
        $p = $this->makePsychologist(['activity_status' => 'active_not_met']);
        $this->payoutCard($p->user);
        $this->holdSession($p);

        $this->buildOnMonday();
        $line = Payout::sole();
        $this->assertSame('blocked_supervision', $line->status);
        $this->assertStringContainsString('супервизии', $line->reason);
        $this->assertDatabaseHas('domain_events', ['name' => 'payout.blocked_by_supervision', 'aggregate_id' => $line->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'payout.blocked_supervision']);
        $this->assertSame('accrued', Accrual::sole()->status);
        $this->assertSame(280000, (int) PayeeBalance::find($p->user_id)->available);
        $this->assertSame(0, $this->gateway->payoutCalls);

        // After the supervision is credited the accumulated balance goes out on the next Monday.
        $this->supervisionMet($p);
        $this->buildOnMonday();
        $this->assertSame('sent', Payout::where('status', '!=', 'blocked_supervision')->sole()->status);
    }

    public function test_requirement_does_not_apply_in_the_approval_month_and_to_payees_without_a_psychologist_profile(): void
    {
        $fresh = $this->makePsychologist(['qualified_at' => now()->subDays(3), 'activity_status' => 'grace']);
        $this->payoutCard($fresh->user);
        $this->holdSession($fresh);

        $supervisor = $this->userWithRole('supervisor');
        $this->payoutCard($supervisor, 'tok_supervisor');
        app(AccrualService::class)->accrueSupervision($this->paidSession($this->makePsychologist()), $supervisor, 300000);

        $this->buildOnMonday();
        $this->assertSame(['sent', 'sent'], Payout::orderBy('amount')->pluck('status')->all());
    }

    public function test_deferred_lines_without_card_below_minimum_or_suspended(): void
    {
        $noCard = $this->makePsychologist();
        $this->supervisionMet($noCard);
        $this->holdSession($noCard);

        $small = $this->eligiblePsychologist(100000);
        $this->holdSession($small);

        $suspended = $this->eligiblePsychologist();
        $this->holdSession($suspended);
        app(PayeeBalanceService::class)->suspend($suspended->user, 'Проверка документов', $this->userWithRole('admin'));

        $this->buildOnMonday();
        $lines = Payout::all()->keyBy('user_id');
        $this->assertSame('deferred', $lines[$noCard->user_id]->status);
        $this->assertSame('Не привязана карта для выплат', $lines[$noCard->user_id]->reason);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $noCard->user_id, 'template_code' => 'payout.no_card']);
        $this->assertSame('deferred', $lines[$small->user_id]->status);
        $this->assertStringContainsString('минимальной выплаты', $lines[$small->user_id]->reason);
        $this->assertSame('deferred', $lines[$suspended->user_id]->status);
        $this->assertStringContainsString('Проверка документов', $lines[$suspended->user_id]->reason);
        $this->assertSame(0, $this->gateway->payoutCalls);
        $this->assertSame(0, Accrual::where('status', '!=', 'accrued')->count());
        $this->assertSame('completed', PayoutRegistry::sole()->status);

        // Fixed next week: card bound, more earned, payouts resumed.
        $this->payoutCard($noCard->user, 'tok_new');
        $this->holdSession($small);
        app(PayeeBalanceService::class)->resume($suspended->user, $this->userWithRole('admin'));
        $this->buildOnMonday();
        $sent = Payout::where('status', 'sent')->get()->keyBy('user_id');
        $this->assertSame(280000, (int) $sent[$noCard->user_id]->amount);
        $this->assertSame(140000, (int) $sent[$small->user_id]->amount);
        $this->assertSame(280000, (int) $sent[$suspended->user_id]->amount);
    }

    public function test_manual_approval_with_excluded_line(): void
    {
        Settings::set('P-PAYOUT-AUTO-APPROVE', false);
        $a = $this->eligiblePsychologist();
        $b = $this->eligiblePsychologist(500000);
        $this->holdSession($a);
        $this->holdSession($b);

        $registry = $this->buildOnMonday();
        $this->assertSame('draft', $registry->status);
        $this->assertSame(2, Payout::where('status', 'in_registry')->count());
        $this->assertSame(0, $this->gateway->payoutCalls);

        $admin = $this->actingAsRole('admin');
        $lineA = Payout::where('user_id', $a->user_id)->sole();
        $this->postJson("/api/v1/admin/payouts/lines/{$lineA->id}/exclude", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/payouts/lines/{$lineA->id}/exclude", ['reason' => 'Уточняем реквизиты'])
            ->assertOk()->assertJsonPath('data.status', 'excluded')->assertJsonPath('data.reason', 'Уточняем реквизиты');
        $this->assertSame('accrued', Accrual::where('user_id', $a->user_id)->sole()->status);
        $this->assertSame(280000, (int) PayeeBalance::find($a->user_id)->available);
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-08', 'action' => 'payout.excluded', 'subject_id' => $lineA->id, 'user_id' => $admin->id]);

        $this->postJson("/api/v1/admin/payouts/registries/{$registry->id}/approve")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertSame('sent', Payout::where('user_id', $b->user_id)->sole()->status);
        $this->assertSame(1, $this->gateway->payoutCalls);
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-08', 'action' => 'payout_registry.approved', 'subject_id' => $registry->id]);

        $this->postJson("/api/v1/admin/payouts/registries/{$registry->id}/approve")->assertUnprocessable();
        $lineB = Payout::where('user_id', $b->user_id)->sole();
        $this->postJson("/api/v1/admin/payouts/lines/{$lineB->id}/exclude", ['reason' => 'Поздно'])->assertUnprocessable();
    }

    public function test_rejected_payout_returns_money_to_the_next_registry(): void
    {
        $p = $this->eligiblePsychologist();
        $this->holdSession($p);
        $this->buildOnMonday();
        $payout = Payout::sole();

        $this->payoutWebhook($payout, 'payout.rejected', 'card_blocked');
        $payout->refresh();
        $this->assertSame('rejected', $payout->status);
        $this->assertSame('card_blocked', $payout->error_code);
        $this->assertSame('accrued', Accrual::sole()->status);
        $this->assertNull(Accrual::sole()->payout_id);
        $balance = PayeeBalance::find($p->user_id);
        $this->assertSame(280000, (int) $balance->available);
        $this->assertSame(0, (int) $balance->in_payout);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'payout.rejected']);

        $this->buildOnMonday();
        $next = Payout::where('status', 'sent')->sole();
        $this->assertNotSame($payout->id, $next->id);
        $this->assertSame(280000, (int) $next->amount);
        $this->assertSame(2, $this->gateway->distinctPayouts());
    }

    public function test_unknown_status_is_resolved_by_status_query_without_a_second_payout(): void
    {
        $p = $this->eligiblePsychologist();
        $this->holdSession($p);
        $this->gateway->throwOnPayout = true;
        $registry = $this->buildOnMonday();
        $payout = Payout::sole();
        $this->assertSame('sent', $payout->status);
        $this->assertSame(0, $this->gateway->distinctPayouts());

        // No webhook within P-PAYOUT-WEBHOOK-WAIT: the gateway still processes it → unknown.
        $this->gateway->throwOnPayout = false;
        $this->gateway->payoutStatusResult = GatewayOperation::PENDING;
        $this->travel(40)->minutes();
        $this->artisan('payout:check-unknown')->assertSuccessful();
        $this->assertSame('unknown', $payout->fresh()->status);

        // Admin retry repeats the call with the same key: the provider keeps one payout.
        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/payouts/registries/{$registry->id}/retry")->assertOk();
        $this->postJson("/api/v1/admin/payouts/registries/{$registry->id}/retry")->assertOk();
        $this->assertSame(1, $this->gateway->distinctPayouts());

        $this->gateway->payoutStatusResult = GatewayOperation::SUCCEEDED;
        $this->artisan('payout:check-unknown')->assertSuccessful();
        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame('paid', Accrual::sole()->status);
        $this->assertSame(1, $this->gateway->distinctPayouts());
        $this->assertSame('completed', $registry->fresh()->status);
    }

    public function test_gateway_unknown_result_moves_line_to_unknown(): void
    {
        $p = $this->eligiblePsychologist();
        $this->holdSession($p);
        $this->gateway->payoutResult = GatewayOperation::UNKNOWN;
        $this->buildOnMonday();
        $this->assertSame('unknown', Payout::sole()->status);

        $this->gateway->payoutStatusResult = GatewayOperation::DECLINED;
        $this->artisan('payout:check-unknown')->assertSuccessful();
        $this->assertSame('rejected', Payout::sole()->status);
        $this->assertSame(280000, (int) PayeeBalance::find($p->user_id)->available);
    }

    public function test_full_refund_after_payout_becomes_negative_correction_carried_into_next_payouts(): void
    {
        $p = $this->eligiblePsychologist();
        $session = $this->holdSession($p);
        $this->buildOnMonday();
        $this->payoutWebhook(Payout::sole(), 'payout.paid');

        $this->refundComplaint($session, 100);
        $original = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('corrected', $original->status);
        $correction = Accrual::where('correction_of_id', $original->id)->sole();
        $this->assertSame('correction', $correction->kind);
        $this->assertSame(-280000, (int) $correction->amount);
        $this->assertSame('accrued', $correction->status);
        $this->assertSame(-280000, (int) PayeeBalance::find($p->user_id)->available);

        // The next session only covers the correction: nothing to pay this week.
        $this->holdSession($p);
        $this->buildOnMonday();
        $this->assertSame(1, Payout::count());
        $this->assertSame(0, (int) PayeeBalance::find($p->user_id)->available);

        // Then the following week pays the new money.
        $this->holdSession($p);
        $this->buildOnMonday();
        $line = Payout::where('status', 'sent')->sole();
        $this->assertSame(280000, (int) $line->amount);
        $this->assertSame('in_registry', $correction->fresh()->status);
    }

    public function test_partial_refund_after_payout_reduces_the_next_payout(): void
    {
        $p = $this->eligiblePsychologist();
        $session = $this->holdSession($p);
        $this->buildOnMonday();
        $this->payoutWebhook(Payout::sole(), 'payout.paid');

        $this->refundComplaint($session, 50);
        $this->assertSame(-140000, (int) PayeeBalance::find($p->user_id)->available);
        $this->assertSame('corrected', Accrual::where('source_id', $session->id)->value('status'));

        $this->holdSession($p);
        $this->buildOnMonday();
        $this->assertSame(140000, (int) Payout::where('status', 'sent')->sole()->amount);
    }

    public function test_refund_while_money_is_in_a_sent_payout_is_corrected_after_payment(): void
    {
        $p = $this->eligiblePsychologist();
        $session = $this->holdSession($p);
        $this->buildOnMonday();
        $payout = Payout::sole();

        $this->refundComplaint($session, 100);
        $original = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('in_registry', $original->status);
        $this->assertSame(-280000, (int) PayeeBalance::find($p->user_id)->available);

        $this->payoutWebhook($payout, 'payout.paid');
        $this->assertSame('corrected', $original->fresh()->status);
        $this->assertSame(-280000, (int) PayeeBalance::find($p->user_id)->available);
    }

    public function test_rejection_folds_pending_corrections_into_the_returned_accrual(): void
    {
        $p = $this->eligiblePsychologist();
        $session = $this->holdSession($p);
        $this->buildOnMonday();
        $payout = Payout::sole();

        $this->refundComplaint($session, 50);
        $this->payoutWebhook($payout, 'payout.rejected');

        $original = Accrual::where('source_id', $session->id)->sole();
        $this->assertSame('accrued', $original->status);
        $this->assertSame(140000, $original->net());
        $this->assertSame('reversed', Accrual::where('correction_of_id', $original->id)->value('status'));
        $this->assertSame(140000, (int) PayeeBalance::find($p->user_id)->available);
    }

    public function test_next_payout_date_follows_the_payout_day_in_moscow_time(): void
    {
        $this->assertSame('2026-10-12 03:00', PayoutRegistryService::nextPayoutAt(CarbonImmutable::parse('2026-10-07 15:00', 'Europe/Moscow'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 03:00', PayoutRegistryService::nextPayoutAt(CarbonImmutable::parse('2026-10-12 02:00', 'Europe/Moscow'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-19 03:00', PayoutRegistryService::nextPayoutAt(CarbonImmutable::parse('2026-10-12 04:00', 'Europe/Moscow'))->format('Y-m-d H:i'));

        Settings::set('P-PAYOUT-PERIOD', 'weekly_friday');
        $this->assertSame('2026-10-09 03:00', PayoutRegistryService::nextPayoutAt(CarbonImmutable::parse('2026-10-07 15:00', 'Europe/Moscow'))->format('Y-m-d H:i'));
        [$start, $boundary] = PayoutRegistryService::periodFor(CarbonImmutable::parse('2026-10-09 03:00', 'Europe/Moscow'));
        $this->assertSame('2026-10-02', $start->toDateString());
        $this->assertSame('2026-10-09', $boundary->toDateString());
    }
}
