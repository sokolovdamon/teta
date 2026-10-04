<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\ScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPsychologistProfiles;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** PRO-03: intervals, exceptions, personal limits, preview (BR-SCHED-01…04, BR-PSY-04). */
class ScheduleApiTest extends TestCase
{
    use BuildsPsychologistProfiles, CreatesPsychologists;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2026-10-05 08:00 MSK.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
        Cache::flush();
    }

    public function test_weekly_intervals_are_validated(): void
    {
        $p = $this->completePsychologist(intervals: []);
        Sanctum::actingAs($p->user);

        $put = fn (array $intervals) => $this->putJson('/api/v1/pro/schedule/intervals', ['intervals' => $intervals]);
        $put([['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00'], ['weekday' => 1, 'starts_at' => '11:30', 'ends_at' => '14:00']])
            ->assertUnprocessable()->assertJsonValidationErrors('intervals');
        $put([['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '10:30']])->assertUnprocessable()->assertJsonValidationErrors('intervals.0.ends_at');
        $put([['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '10:00']])->assertUnprocessable()->assertJsonValidationErrors('intervals.0.ends_at');
        $put([['weekday' => 1, 'starts_at' => '25:00', 'ends_at' => '26:00']])->assertUnprocessable();
        $put([['weekday' => 8, 'starts_at' => '10:00', 'ends_at' => '12:00']])->assertUnprocessable();

        // Touching intervals are fine, and a day may end at midnight.
        $put([
            ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00'],
            ['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '14:00'],
            ['weekday' => 5, 'starts_at' => '22:00', 'ends_at' => '24:00'],
        ])->assertOk()->assertJsonCount(3, 'data.intervals')->assertJsonPath('data.intervals.2.ends_at', '24:00');

        // Pair sessions need longer intervals.
        $p->forceFill(['works_individual' => false, 'works_pair' => true, 'price_pair' => 700000])->save();
        Sanctum::actingAs($p->user->fresh());
        $put([['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '11:00']])->assertUnprocessable();
    }

    public function test_slots_follow_intervals_up_to_midnight(): void
    {
        $p = $this->completePsychologist(intervals: []);
        Sanctum::actingAs($p->user);
        $this->putJson('/api/v1/pro/schedule/intervals', ['intervals' => [['weekday' => 2, 'starts_at' => '22:00', 'ends_at' => '24:00']]])->assertOk();
        // 22:00 and 23:00 MSK on Tuesday.
        $this->getJson('/api/v1/pro/schedule/preview?days=2')->assertOk()
            ->assertJsonPath('data.slots', ['2026-10-06T19:00:00Z', '2026-10-06T20:00:00Z']);
    }

    public function test_single_interval_crud_and_ownership(): void
    {
        $p = $this->completePsychologist(intervals: [[1, '10:00', '12:00']]);
        $other = $this->completePsychologist();
        Sanctum::actingAs($p->user);

        $id = $this->postJson('/api/v1/pro/schedule/intervals', ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '15:00'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/pro/schedule/intervals', ['weekday' => 1, 'starts_at' => '11:00', 'ends_at' => '13:30'])->assertUnprocessable();
        $this->patchJson("/api/v1/pro/schedule/intervals/{$id}", ['ends_at' => '16:00'])->assertOk()->assertJsonPath('data.ends_at', '16:00');
        $this->patchJson("/api/v1/pro/schedule/intervals/{$id}", ['starts_at' => '11:00'])->assertUnprocessable();
        $this->deleteJson("/api/v1/pro/schedule/intervals/{$id}")->assertOk()->assertJsonCount(1, 'data.intervals');

        $foreign = ScheduleInterval::where('psychologist_id', $other->id)->first();
        $this->patchJson("/api/v1/pro/schedule/intervals/{$foreign->id}", ['ends_at' => '21:00'])->assertNotFound();
        $this->deleteJson("/api/v1/pro/schedule/intervals/{$foreign->id}")->assertNotFound();
    }

    public function test_exceptions_block_slots_and_cannot_cover_booked_sessions(): void
    {
        $p = $this->completePsychologist(intervals: [[2, '10:00', '14:00'], [3, '10:00', '14:00']]);
        $client = User::factory()->withRole('client')->create();
        $this->makeSession($p, $client, CarbonImmutable::parse('2026-10-07 11:00', 'Europe/Moscow'));
        Sanctum::actingAs($p->user);

        $this->postJson('/api/v1/pro/schedule/exceptions', [
            'kind' => 'vacation', 'starts_at' => '2026-10-06T00:00:00+03:00', 'ends_at' => '2026-10-08T00:00:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at')->assertJsonCount(1, 'sessions');

        $this->postJson('/api/v1/pro/schedule/exceptions', ['kind' => 'blocked', 'starts_at' => '2026-10-06T12:00:00+03:00', 'ends_at' => '2026-10-06T10:00:00+03:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson('/api/v1/pro/schedule/exceptions', ['kind' => 'blocked', 'starts_at' => '2026-10-01T00:00:00+03:00', 'ends_at' => '2026-10-02T00:00:00+03:00'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/pro/schedule/exceptions', ['kind' => 'holiday', 'starts_at' => '2026-10-06T00:00:00+03:00', 'ends_at' => '2026-10-07T00:00:00+03:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');

        $id = $this->postJson('/api/v1/pro/schedule/exceptions', [
            'kind' => 'vacation', 'starts_at' => '2026-10-06T00:00:00+03:00', 'ends_at' => '2026-10-07T00:00:00+03:00', 'comment' => 'Отпуск',
        ])->assertCreated()->assertJsonPath('data.starts_at', '2026-10-05T21:00:00Z')->json('data.id');

        $slots = $this->getJson('/api/v1/pro/schedule/preview?days=3')->assertOk()->json('data.slots');
        $this->assertNotContains('2026-10-06T07:00:00Z', $slots);
        $this->assertContains('2026-10-07T07:00:00Z', $slots);
        $this->assertNotContains('2026-10-07T08:00:00Z', $slots, 'the booked session occupies 11:00');

        $this->getJson('/api/v1/pro/schedule')->assertJsonCount(1, 'data.exceptions');
        $this->deleteJson("/api/v1/pro/schedule/exceptions/{$id}")->assertOk()->assertJsonCount(0, 'data.exceptions');
        $this->assertContains('2026-10-06T07:00:00Z', $this->getJson('/api/v1/pro/schedule/preview?days=3')->json('data.slots'));
    }

    public function test_personal_limits_may_only_tighten_platform_limits(): void
    {
        $p = $this->completePsychologist(intervals: [[1, '08:00', '20:00']]);
        Sanctum::actingAs($p->user);

        $this->patchJson('/api/v1/pro/schedule/settings', ['min_lead_minutes' => 60])->assertUnprocessable()->assertJsonValidationErrors('min_lead_minutes');
        $this->patchJson('/api/v1/pro/schedule/settings', ['horizon_days' => 60])->assertUnprocessable()->assertJsonValidationErrors('horizon_days');
        $this->patchJson('/api/v1/pro/schedule/settings', ['buffer_minutes' => 5])->assertUnprocessable()->assertJsonValidationErrors('buffer_minutes');
        $this->patchJson('/api/v1/pro/schedule/settings', ['timezone' => 'Mars/Olympus'])->assertUnprocessable()->assertJsonValidationErrors('timezone');

        $this->patchJson('/api/v1/pro/schedule/settings', ['min_lead_minutes' => 480, 'buffer_minutes' => 20, 'horizon_days' => 14, 'timezone' => 'Asia/Yekaterinburg'])
            ->assertOk()
            ->assertJsonPath('data.effective.min_lead_minutes', 480)
            ->assertJsonPath('data.effective.buffer_minutes', 20)
            ->assertJsonPath('data.effective.horizon_days', 14)
            ->assertJsonPath('data.timezone', 'Asia/Yekaterinburg');

        // Now 10:00 in Yekaterinburg (UTC+5) + 8 h lead → not before 18:00 local; slots go 08:00, 09:10 … 18:30 (50 + 20 min).
        $slots = $this->getJson('/api/v1/pro/schedule/preview?days=1')->json('data.slots');
        $this->assertSame('2026-10-05T13:30:00Z', $slots[0]);

        $this->patchJson('/api/v1/pro/schedule/settings', ['min_lead_minutes' => null])->assertOk()->assertJsonPath('data.effective.min_lead_minutes', 180);
    }

    public function test_publication_follows_working_intervals(): void
    {
        $p = $this->completePsychologist(['is_published' => false], []);
        Sanctum::actingAs($p->user);

        $this->putJson('/api/v1/pro/schedule/intervals', ['intervals' => [['weekday' => 3, 'starts_at' => '10:00', 'ends_at' => '18:00']]])->assertOk();
        $this->assertTrue($p->fresh()->is_published);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.profile.published', 'aggregate_id' => $p->id]);
        $this->getJson('/api/v1/psychologists')->assertJsonCount(1, 'data');

        $this->putJson('/api/v1/pro/schedule/intervals', ['intervals' => []])->assertOk();
        $this->assertFalse($p->fresh()->is_published);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.profile.unpublished', 'aggregate_id' => $p->id]);
        $this->getJson('/api/v1/psychologists')->assertJsonCount(0, 'data');
    }

    public function test_only_psychologists_manage_schedules(): void
    {
        $this->actingAsRole('client');
        $this->getJson('/api/v1/pro/schedule')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/pro/schedule')->assertUnauthorized();
    }
}
