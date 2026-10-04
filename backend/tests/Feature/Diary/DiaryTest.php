<?php

namespace Tests\Feature\Diary;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Diary\Models\EmotionTag;
use App\Support\Events\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiaryTest extends TestCase
{
    public function test_check_in_is_offered_once_a_day_and_can_be_skipped(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Europe/Moscow'));
        $this->actingAsRole('client');

        $this->getJson('/api/v1/diary/prompt')->assertOk()->assertJsonPath('data.show', true)
            ->assertJsonPath('data.today', '2026-10-05');
        $this->assertNotEmpty($this->getJson('/api/v1/diary/prompt')->json('data.tags'));

        $this->postJson('/api/v1/diary/skip')->assertOk();
        $this->getJson('/api/v1/diary/prompt')->assertJsonPath('data.show', false);

        // Next day in the client's time zone the check-in is offered again.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:30', 'Europe/Moscow'));
        $this->getJson('/api/v1/diary/prompt')->assertJsonPath('data.show', true);

        $this->postJson('/api/v1/diary/entries', ['mood' => 4])->assertCreated();
        $this->getJson('/api/v1/diary/prompt')->assertJsonPath('data.show', false);
    }

    public function test_days_are_counted_in_the_client_time_zone(): void
    {
        $client = User::factory()->withRole('client')->create(['timezone' => 'Asia/Vladivostok']);
        Sanctum::actingAs($client->fresh());
        // 2026-10-05 20:00 UTC is already 06:00 on 2026-10-06 in Vladivostok.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 20:00', 'UTC'));

        $this->postJson('/api/v1/diary/entries', ['mood' => 3])->assertCreated()->assertJsonPath('data.local_date', '2026-10-06');
        $this->getJson('/api/v1/diary/prompt')->assertJsonPath('data.today', '2026-10-06')->assertJsonPath('data.show', false);
    }

    public function test_entry_validation_and_note_encrypted_at_rest(): void
    {
        $client = $this->actingAsRole('client');
        $tags = EmotionTag::active()->ordered()->limit(2)->pluck('id')->all();

        $this->postJson('/api/v1/diary/entries', ['mood' => 6])->assertUnprocessable()->assertJsonValidationErrors('mood');
        $this->postJson('/api/v1/diary/entries', ['mood' => 3, 'tag_ids' => ['00000000-0000-0000-0000-000000000000']])->assertJsonValidationErrors('tag_ids.0');
        $this->postJson('/api/v1/diary/entries', ['mood' => 3, 'note' => str_repeat('а', 501)])->assertJsonValidationErrors('note');

        $res = $this->postJson('/api/v1/diary/entries', ['mood' => 2, 'tag_ids' => $tags, 'note' => 'Тревожно перед экзаменом'])->assertCreated();
        $res->assertJsonPath('data.note', 'Тревожно перед экзаменом')->assertJsonCount(2, 'data.tags');

        $raw = DB::table('diary_entries')->where('client_id', $client->id)->value('note');
        $this->assertNotSame('Тревожно перед экзаменом', $raw);
        $this->assertStringNotContainsString('экзамен', (string) $raw);

        $this->getJson('/api/v1/diary/entries')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mood', 2);
    }

    public function test_client_sees_and_deletes_only_own_entries(): void
    {
        $other = User::factory()->withRole('client')->create();
        $foreign = DiaryEntry::create(['client_id' => $other->id, 'mood' => 1, 'recorded_at' => now(), 'local_date' => now()->toDateString()]);

        $this->actingAsRole('client');
        $this->postJson('/api/v1/diary/entries', ['mood' => 5])->assertCreated();
        $this->getJson('/api/v1/diary/entries')->assertJsonCount(1, 'data')->assertJsonPath('data.0.mood', 5);
        $this->deleteJson("/api/v1/diary/entries/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('diary_entries', ['id' => $foreign->id]);
    }

    public function test_diary_is_for_clients_only(): void
    {
        $this->actingAsRole('psychologist');
        $this->getJson('/api/v1/diary/prompt')->assertForbidden();
        $this->postJson('/api/v1/diary/entries', ['mood' => 3])->assertForbidden();
    }

    public function test_dynamics_aggregates_by_day_and_week_with_top_tags(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/Moscow'));
        $client = $this->actingAsRole('client');
        [$calm, $anxiety] = EmotionTag::active()->ordered()->limit(2)->get()->all();

        $this->entry($client, '2026-10-19 10:00', 2, [$anxiety->id]);
        $this->entry($client, '2026-10-19 20:00', 4, [$anxiety->id, $calm->id]);
        $this->entry($client, '2026-10-12 10:00', 5, []);
        $this->entry($client, '2026-08-01 10:00', 1, []);

        $res = $this->getJson('/api/v1/diary/dynamics?period=month')->assertOk();
        $res->assertJsonPath('data.group', 'day')->assertJsonPath('data.to', '2026-10-20');
        $points = collect($res->json('data.points'))->keyBy('date');
        $this->assertCount(2, $points);
        $this->assertSame(3.0, (float) $points['2026-10-19']['avg_mood']);
        $this->assertSame(2, $points['2026-10-19']['entries']);
        $this->assertSame($anxiety->id, $res->json('data.tags.0.id'));
        $this->assertSame(2, $res->json('data.tags.0.count'));
        $this->assertSame(3, $res->json('data.summary.entries'));
        $this->assertArrayNotHasKey('note', $res->json('data.points.0'));

        $weekly = $this->getJson('/api/v1/diary/dynamics?period=year&group=week')->assertOk();
        $this->assertSame('week', $weekly->json('data.group'));
        $this->assertContains('2026-10-19', array_column($weekly->json('data.points'), 'date'));
        $this->assertContains('2026-07-27', array_column($weekly->json('data.points'), 'date'));
    }

    public function test_admin_edits_tags_but_never_sees_entries(): void
    {
        $client = User::factory()->withRole('client')->create();
        $tag = EmotionTag::first();
        DiaryEntry::create(['client_id' => $client->id, 'mood' => 2, 'tag_ids' => [$tag->id], 'recorded_at' => now(), 'local_date' => now()->toDateString()]);

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/diary/emotion-tags')->assertOk();
        $id = $this->postJson('/api/v1/admin/diary/emotion-tags', ['title' => 'Вдохновение'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/admin/diary/emotion-tags/{$id}", ['sort' => 1])->assertOk();
        $this->deleteJson("/api/v1/admin/diary/emotion-tags/{$id}")->assertOk()->assertJsonPath('deactivated', false);
        // A used tag is hidden from the form, the history keeps it.
        $this->deleteJson("/api/v1/admin/diary/emotion-tags/{$tag->id}")->assertOk()->assertJsonPath('deactivated', true);
        $this->assertFalse($tag->fresh()->is_active);

        // There is no admin endpoint for entries; the client endpoints are closed to admins.
        $this->getJson('/api/v1/diary/entries')->assertForbidden();
        $this->getJson('/api/v1/diary/dynamics')->assertForbidden();

        $this->actingAsRole('client');
        $this->postJson('/api/v1/admin/diary/emotion-tags', ['title' => 'Злость'])->assertForbidden();
    }

    public function test_anonymized_user_diary_is_destroyed_and_only_the_fact_is_audited(): void
    {
        $client = User::factory()->withRole('client')->create();
        DiaryEntry::create(['client_id' => $client->id, 'mood' => 1, 'note' => 'Очень личное', 'recorded_at' => now(), 'local_date' => now()->toDateString()]);
        DiaryEntry::create(['client_id' => $client->id, 'mood' => 2, 'recorded_at' => now(), 'local_date' => now()->toDateString()]);

        Outbox::record('account.user.anonymized', $client, ['user_id' => $client->id]);

        $this->assertSame(0, DiaryEntry::where('client_id', $client->id)->count());
        $log = AuditLog::where('action', 'diary.entries.destroyed')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, $log->changes['count']);
        $this->assertStringNotContainsString('личное', json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /** @param  list<string>  $tags */
    private function entry(User $client, string $moscowTime, int $mood, array $tags): DiaryEntry
    {
        $at = CarbonImmutable::parse($moscowTime, 'Europe/Moscow');

        return DiaryEntry::create([
            'client_id' => $client->id, 'mood' => $mood, 'tag_ids' => $tags ?: null,
            'recorded_at' => $at, 'local_date' => $at->toDateString(),
        ]);
    }
}
