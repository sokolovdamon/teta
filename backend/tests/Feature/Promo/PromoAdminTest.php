<?php

namespace Tests\Feature\Promo;

use App\Modules\Promo\Models\PromoBatch;
use App\Modules\Promo\Models\PromoCode;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** ADM-09: promo codes, batches, statistics and referral settings; every action is audited. */
class PromoAdminTest extends TestCase
{
    use CreatesPsychologists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Moscow'));
    }

    public function test_admin_creates_publishes_edits_and_deactivates_a_code(): void
    {
        $admin = $this->actingAsRole('admin');
        $psy = $this->makePsychologist();

        $res = $this->postJson('/api/v1/admin/promo/codes', [
            'code' => 'autumn20', 'title' => 'Осень', 'type' => 'percent', 'value' => 20, 'kind' => 'mass',
            'valid_from' => '2026-10-10T00:00:00+03:00', 'valid_until' => '2026-11-30T23:59:00+03:00',
            'total_limit' => 100, 'per_user_limit' => 1, 'min_amount' => 300000,
            'restrictions' => ['service_types' => ['individual'], 'psychologist_ids' => [$psy->id], 'segment' => ['new_clients' => true, 'min_held_sessions' => null]],
        ])->assertCreated()
            ->assertJsonPath('data.code', 'AUTUMN20')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.restrictions.service_types', ['individual'])
            ->assertJsonPath('data.restrictions.segment', ['new_clients' => true]);
        $id = $res->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-09', 'action' => 'promo.created', 'subject_id' => $id, 'user_id' => $admin->id]);

        $this->postJson('/api/v1/admin/promo/codes', ['code' => 'AUTUMN20', 'type' => 'percent', 'value' => 10, 'kind' => 'mass'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/promo/codes', ['type' => 'percent', 'value' => 150, 'kind' => 'mass'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson('/api/v1/admin/promo/codes', ['type' => 'percent', 'value' => 10, 'kind' => 'mass', 'valid_from' => '2026-10-10', 'valid_until' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('valid_until');

        // Draft terms can change.
        $this->putJson("/api/v1/admin/promo/codes/{$id}", ['value' => 25])->assertOk()->assertJsonPath('data.value', 25);

        // Published before the start → scheduled; terms are frozen, limits and texts can change.
        $this->postJson("/api/v1/admin/promo/codes/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->putJson("/api/v1/admin/promo/codes/{$id}", ['value' => 90, 'type' => 'fixed', 'title' => 'Осень-2026', 'total_limit' => 200])->assertOk()
            ->assertJsonPath('data.value', 25)->assertJsonPath('data.type', 'percent')
            ->assertJsonPath('data.title', 'Осень-2026')->assertJsonPath('data.total_limit', 200);
        $this->postJson("/api/v1/admin/promo/codes/{$id}/publish")->assertUnprocessable();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 00:05', 'Europe/Moscow'));
        $this->artisan('promo:sync-statuses')->assertSuccessful();
        $this->getJson("/api/v1/admin/promo/codes/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.stats.reserved_total', 0)
            ->assertJsonCount(3, 'data.history');

        $this->postJson("/api/v1/admin/promo/codes/{$id}/deactivate", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/promo/codes/{$id}/deactivate", ['reason' => 'Акция отменена'])->assertOk()
            ->assertJsonPath('data.status', 'deactivated')->assertJsonPath('data.deactivation_reason', 'Акция отменена');
        $this->assertDatabaseHas('audit_logs', ['action' => 'promo.deactivated', 'subject_id' => $id, 'comment' => 'Акция отменена']);
        $this->putJson("/api/v1/admin/promo/codes/{$id}", ['title' => 'x'])->assertUnprocessable();
    }

    public function test_publish_with_started_period_activates_and_generated_code(): void
    {
        $this->actingAsRole('admin');
        $res = $this->postJson('/api/v1/admin/promo/codes', ['type' => 'first_session', 'value' => 100, 'kind' => 'mass', 'publish' => true])
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.discount_label', 'первая сессия −100 %');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $res->json('data.code'));
    }

    public function test_batch_generation_export_and_deactivation(): void
    {
        $admin = $this->actingAsRole('admin');
        $res = $this->postJson('/api/v1/admin/promo/batches', [
            'title' => 'Партнёрская акция', 'size' => 25, 'prefix' => 'gift', 'type' => 'fixed', 'value' => 50000,
            'valid_until' => '2026-12-31T23:59:00+03:00', 'publish' => true,
        ])->assertCreated()
            ->assertJsonPath('data.size', 25)
            ->assertJsonPath('data.prefix', 'GIFT')
            ->assertJsonPath('data.codes_by_status.active', 25)
            ->assertJsonPath('data.terms.discount_label', '−500 ₽');
        $batch = PromoBatch::findOrFail($res->json('data.id'));
        $codes = PromoCode::where('promo_batch_id', $batch->id)->pluck('code');
        $this->assertCount(25, $codes->unique());
        $this->assertTrue($codes->every(fn ($c) => str_starts_with($c, 'GIFT-')));
        $this->assertSame(25, PromoCode::where('promo_batch_id', $batch->id)->where('kind', 'individual')->where('total_limit', 1)->count());
        $this->assertDatabaseHas('state_transitions', ['model_type' => 'promo_code', 'to' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'promo.batch_created', 'subject_id' => $batch->id, 'user_id' => $admin->id]);

        // Batch codes do not flood the code list unless the batch is selected.
        $this->getJson('/api/v1/admin/promo/codes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/admin/promo/codes?batch_id={$batch->id}")->assertOk()->assertJsonPath('meta.total', 25);
        $this->getJson('/api/v1/admin/promo/batches')->assertOk()->assertJsonPath('data.0.id', $batch->id);
        $this->getJson("/api/v1/admin/promo/batches/{$batch->id}")->assertOk()->assertJsonCount(25, 'data.codes');

        $csv = $this->get("/api/v1/admin/promo/batches/{$batch->id}/export")->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertCount(26, $lines);
        $this->assertStringStartsWith("\xEF\xBB\xBFКод;Скидка;Статус", $lines[0]);
        $this->assertStringStartsWith('GIFT-', $lines[1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'promo.batch_exported', 'subject_id' => $batch->id]);

        $this->postJson("/api/v1/admin/promo/batches/{$batch->id}/deactivate", ['reason' => 'Партнёр отказался'])->assertOk()->assertJsonPath('affected', 25);
        $this->assertSame(25, PromoCode::where('promo_batch_id', $batch->id)->where('status', 'deactivated')->count());

        $this->postJson('/api/v1/admin/promo/batches', ['title' => 'Слишком много', 'size' => 100000, 'type' => 'fixed', 'value' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('size');
    }

    public function test_draft_batch_is_published_later(): void
    {
        $this->actingAsRole('admin');
        $id = $this->postJson('/api/v1/admin/promo/batches', ['title' => 'Черновик', 'size' => 3, 'type' => 'percent', 'value' => 15])
            ->assertCreated()->assertJsonPath('data.codes_by_status.draft', 3)->json('data.id');
        $this->postJson("/api/v1/admin/promo/batches/{$id}/publish")->assertOk()->assertJsonPath('affected', 3)->assertJsonPath('data.codes_by_status.active', 3);
    }

    public function test_overview_lookups_and_referral_settings(): void
    {
        $this->actingAsRole('admin');
        $psy = $this->makePsychologist();

        $this->getJson('/api/v1/admin/promo/overview')->assertOk()->assertJsonPath('data.reserved_total', 0);
        $lookups = $this->getJson('/api/v1/admin/promo/lookups?q='.urlencode($psy->user->email))->assertOk();
        $this->assertContains($psy->id, collect($lookups->json('data.psychologists'))->pluck('id')->all());
        $this->assertCount(3, $lookups->json('data.price_categories'));
        $this->assertSame($psy->user_id, $lookups->json('data.users.0.id'));

        $this->getJson('/api/v1/admin/promo/referral-settings')->assertOk()
            ->assertJsonPath('data.friend_discount', 50)->assertJsonPath('data.reward_type', 'fixed')->assertJsonPath('data.reward_value', 100000);
        $this->putJson('/api/v1/admin/promo/referral-settings', ['friend_discount' => 40, 'reward_type' => 'percent', 'reward_value' => 150, 'validity_days' => 60])
            ->assertUnprocessable()->assertJsonValidationErrors('reward_value');
        $this->putJson('/api/v1/admin/promo/referral-settings', ['friend_discount' => 40, 'reward_type' => 'percent', 'reward_value' => 15, 'validity_days' => 60])
            ->assertOk()->assertJsonPath('data.reward_type', 'percent');
        $this->assertSame(40, Settings::get('P-REFERRAL-FRIEND-DISCOUNT'));
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-09', 'action' => 'promo.referral_settings_updated']);
    }

    public function test_promo_admin_requires_permissions(): void
    {
        $this->actingAsRole('client');
        $this->getJson('/api/v1/admin/promo/codes')->assertForbidden();
        $this->postJson('/api/v1/admin/promo/codes', ['type' => 'percent', 'value' => 10, 'kind' => 'mass'])->assertForbidden();
    }
}
