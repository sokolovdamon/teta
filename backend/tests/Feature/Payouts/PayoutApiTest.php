<?php

namespace Tests\Feature\Payouts;

use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payouts\Models\PayoutCardBinding;
use App\Modules\Payouts\Models\PayoutRegistry;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPsychologists;
use Tests\Concerns\PayoutFixtures;
use Tests\TestCase;

/** PRO-08, PRO-09 and ADM-08 endpoints. */
class PayoutApiTest extends TestCase
{
    use CreatesPsychologists, PayoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Moscow'));
        $this->fakeGateway();
    }

    public function test_pro_payouts_overview_shows_balance_requirement_card_and_checks(): void
    {
        $p = $this->makePsychologist(['activity_status' => 'active_not_met']);
        $this->holdSession($p);
        Sanctum::actingAs($p->user->fresh());

        $this->getJson('/api/v1/pro/payouts')->assertOk()
            ->assertJsonPath('data.balance.available', 280000)
            ->assertJsonPath('data.commission_percent', 30)
            ->assertJsonPath('data.min_amount', 100000)
            ->assertJsonPath('data.next_payout_at', CarbonImmutable::parse('2026-10-12 03:00', 'Europe/Moscow')->toIso8601String())
            ->assertJsonPath('data.supervision.applies', true)
            ->assertJsonPath('data.supervision.met', false)
            ->assertJsonPath('data.supervision.payout_allowed', false)
            ->assertJsonPath('data.card', null)
            ->assertJsonPath('data.checks.0.code', 'supervision')
            ->assertJsonPath('data.checks.0.ok', false)
            ->assertJsonPath('data.checks.1.ok', false)
            ->assertJsonPath('data.checks.3.ok', true);

        $this->supervisionMet($p);
        $this->payoutCard($p->user);
        $this->getJson('/api/v1/pro/payouts')->assertOk()
            ->assertJsonPath('data.supervision.payout_allowed', true)
            ->assertJsonPath('data.card.card_mask', '2200 00** **** 0001');
    }

    public function test_payout_card_binding_confirmation_and_removal(): void
    {
        $p = $this->makePsychologist();
        Sanctum::actingAs($p->user->fresh());

        $res = $this->postJson('/api/v1/pro/payouts/card')->assertCreated()->assertJsonPath('data.status', 'pending');
        $bindingId = $res->json('data.id');
        $this->assertStringStartsWith('/pay/emulator/', $res->json('data.confirmation_url'));
        $request = $this->gateway->bindings[PayoutCardBinding::find($bindingId)->idempotency_key];
        $this->assertStringContainsString('/pro/payouts?binding='.$bindingId, $request->returnUrl);

        $this->postJson("/api/v1/pro/payouts/card/bindings/{$bindingId}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.card.purpose', 'payout')
            ->assertJsonPath('data.card.card_mask', '2200 00** **** 0001');
        $this->assertDatabaseHas('payment_methods', ['user_id' => $p->user_id, 'purpose' => 'payout', 'status' => 'active', 'token' => 'tok_payout_0001']);

        // A new card replaces the old one.
        $this->gateway->card = ['token' => 'tok_payout_0002', 'mask' => '2200 00** **** 0002', 'brand' => 'MIR', 'exp_month' => 1, 'exp_year' => 2031];
        $this->gateway->bindingResult = GatewayOperation::SUCCEEDED;
        $this->postJson('/api/v1/pro/payouts/card')->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(['tok_payout_0002'], PaymentMethod::where('user_id', $p->user_id)->where('status', 'active')->pluck('token')->all());

        // Someone else's binding is not visible.
        $other = $this->makePsychologist();
        Sanctum::actingAs($other->user->fresh());
        $this->postJson("/api/v1/pro/payouts/card/bindings/{$bindingId}/confirm")->assertNotFound();

        Sanctum::actingAs($p->user->fresh());
        $this->deleteJson('/api/v1/pro/payouts/card')->assertOk();
        $this->assertSame(0, PaymentMethod::where('user_id', $p->user_id)->where('status', 'active')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_card.removed', 'subject_id' => $p->user_id]);
    }

    public function test_declined_binding(): void
    {
        $p = $this->makePsychologist();
        Sanctum::actingAs($p->user->fresh());
        $this->gateway->bindingResult = GatewayOperation::DECLINED;

        $this->postJson('/api/v1/pro/payouts/card')->assertCreated()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.error_code', 'card_declined')
            ->assertJsonPath('data.confirmation_url', null);
        $this->assertSame(0, PaymentMethod::count());
    }

    public function test_pro_stats_summary_accruals_and_csv_export(): void
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $this->supervisionMet($p);
        $client = $this->userWithRole('client');
        $held = $this->holdSession($p, $client);
        $this->holdSession($p);
        $this->refundComplaint($held, 50);
        Sanctum::actingAs($p->user->fresh());

        $this->getJson('/api/v1/pro/stats?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertJsonPath('data.sessions.held', 2)
            ->assertJsonPath('data.totals.accrued', 560000)
            ->assertJsonPath('data.totals.reversed', 140000)
            ->assertJsonPath('data.totals.net', 420000)
            ->assertJsonPath('data.balance.available', 420000);

        $rows = $this->getJson('/api/v1/pro/stats/accruals?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $reversed = collect($rows)->firstWhere('reversed_amount', 140000);
        $this->assertSame('Проведённая сессия', $reversed['kind_label']);
        $this->assertSame('Начислено', $reversed['status_label']);
        $this->assertStringContainsString('жалобе', $reversed['reason']);
        $this->assertSame('reversal', $reversed['adjustments'][0]['type']);
        $this->assertSame($client->name.' '.mb_substr($client->last_name, 0, 1).'.', $reversed['session']['client_name']);

        $this->getJson('/api/v1/pro/stats/accruals?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pro/stats?from=2026-10-31&to=2026-10-01')->assertUnprocessable();

        $csv = $this->get('/api/v1/pro/stats/export?from=2026-10-01&to=2026-10-31')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('"Дата начисления";Вид;', $lines[0]);
        $this->assertStringContainsString('2800,00', $body);
        $this->assertStringContainsString('1400,00', $body);
    }

    public function test_pro_endpoints_require_psychologist_role(): void
    {
        $this->actingAsRole('client');
        $this->getJson('/api/v1/pro/payouts')->assertForbidden();
        $this->getJson('/api/v1/pro/stats')->assertForbidden();
        $this->getJson('/api/v1/admin/payouts/registries')->assertForbidden();
    }

    public function test_admin_registry_views_settings_blocked_list_and_suspension(): void
    {
        $blocked = $this->makePsychologist(['activity_status' => 'active_not_met']);
        $this->payoutCard($blocked->user);
        $this->holdSession($blocked);
        $ok = $this->makePsychologist();
        $this->supervisionMet($ok);
        $this->payoutCard($ok->user);
        $this->holdSession($ok);

        $admin = $this->actingAsRole('admin');
        $this->travelTo($this->nextMonday());
        $this->postJson('/api/v1/admin/payouts/registries')->assertCreated()
            ->assertJsonPath('data.period_start', '2026-10-05')
            ->assertJsonPath('data.summary.blocked', 1)
            ->assertJsonPath('data.summary.lines', 1);
        $registry = PayoutRegistry::sole();
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_registry.built', 'user_id' => $admin->id]);

        $this->getJson('/api/v1/admin/payouts/registries')->assertOk()->assertJsonPath('data.0.id', $registry->id);
        $detail = $this->getJson("/api/v1/admin/payouts/registries/{$registry->id}")->assertOk()->assertJsonCount(2, 'data.lines');
        $this->assertSame('sent', $detail->json('data.lines.0.status'));
        $this->assertSame($ok->user->email, $detail->json('data.lines.0.payee.email'));
        $this->assertSame('blocked_supervision', $detail->json('data.lines.1.status'));

        $this->getJson('/api/v1/admin/payouts/settings')->assertOk()
            ->assertJsonPath('data.parameters.P-PAYOUT-MIN.value', 100000)
            ->assertJsonPath('data.parameters.P-PAYOUT-AUTO-APPROVE.value', true)
            ->assertJsonPath('data.payout_weekday', 1);

        $this->getJson('/api/v1/admin/payouts/blocked')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payee.id', $blocked->user_id)
            ->assertJsonPath('data.0.available', 280000);

        $this->postJson("/api/v1/admin/payouts/payees/{$ok->user_id}/suspend", [])->assertUnprocessable();
        $this->postJson("/api/v1/admin/payouts/payees/{$ok->user_id}/suspend", ['reason' => 'Проверка документов'])->assertOk()
            ->assertJsonPath('data.payouts_suspended', true)
            ->assertJsonPath('data.suspended_reason', 'Проверка документов');
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-08', 'action' => 'payouts.suspended', 'subject_id' => $ok->user_id]);
        $this->getJson('/api/v1/admin/payouts/payees?suspended=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.payee.id', $ok->user_id);
        $this->getJson("/api/v1/admin/payouts/payees/{$ok->user_id}")->assertOk()->assertJsonPath('data.balance.payouts_suspended', true)->assertJsonCount(1, 'data.payouts');

        // The psychologist sees the reason in PRO-09.
        Sanctum::actingAs($ok->user->fresh());
        $this->getJson('/api/v1/pro/payouts')->assertOk()
            ->assertJsonPath('data.balance.suspended_reason', 'Проверка документов')
            ->assertJsonPath('data.checks.2.ok', false);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/payouts/payees/{$ok->user_id}/resume")->assertOk()->assertJsonPath('data.payouts_suspended', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payouts.resumed', 'subject_id' => $ok->user_id]);
    }
}
