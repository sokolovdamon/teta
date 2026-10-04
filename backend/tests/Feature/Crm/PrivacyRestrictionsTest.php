<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Crm\Models\ClientCard;
use App\Modules\Crm\Models\PsychologistNote;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Events\DeliverDomainEvent;
use App\Support\Events\DomainEvent;
use App\Support\Events\Outbox;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/**
 * Hard restrictions of TZ v2, section 8 (DEC-41, DM-08, BR-RBAC-07, BR-RBAC-08): notes — only the author;
 * diary and session history — only the client's psychologist. No role, including super_admin, opens them.
 */
class PrivacyRestrictionsTest extends TestCase
{
    use CreatesPsychologists;

    public function test_other_psychologist_admin_and_super_admin_cannot_open_card_notes_diary_or_sessions(): void
    {
        [$psy, $client] = $this->pair();
        $this->as($psy);
        $noteId = $this->postJson("/api/v1/pro/clients/{$client->id}/notes", ['body' => 'Гипотеза о сценарии'])->assertCreated()->json('data.id');

        $other = $this->makePsychologist();
        $this->as($other);
        foreach (['', '/notes', '/diary', '/sessions', '/recommendations'] as $suffix) {
            $this->getJson("/api/v1/pro/clients/{$client->id}{$suffix}")->assertNotFound();
        }
        $this->patchJson("/api/v1/pro/clients/{$client->id}/notes/{$noteId}", ['body' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/pro/clients/{$client->id}/notes/{$noteId}")->assertNotFound();
        $this->postJson("/api/v1/pro/clients/{$client->id}/finish")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/pro/clients')->assertOk()->json('data'));

        $this->actingAsRole('admin');
        foreach (['', '/notes', '/diary', '/sessions'] as $suffix) {
            $this->getJson("/api/v1/pro/clients/{$client->id}{$suffix}")->assertForbidden();
        }

        // super_admin passes role middleware but has no psychologist profile: still no access.
        $this->actingAsRole('super_admin');
        foreach (['', '/notes', '/diary', '/sessions'] as $suffix) {
            $this->getJson("/api/v1/pro/clients/{$client->id}{$suffix}")->assertForbidden();
        }
        // ...and the client endpoints are scoped to the caller: nothing of the client leaks.
        DiaryEntry::create(['client_id' => $client->id, 'mood' => 1, 'note' => 'Секрет', 'recorded_at' => now(), 'local_date' => now()->toDateString()]);
        $this->assertSame([], $this->getJson('/api/v1/diary/entries')->assertOk()->json('data'));

        // A super_admin who is also a psychologist sees only their own clients.
        $superPsy = $this->makePsychologist();
        DB::table('role_user')->insert(['role_id' => DB::table('roles')->where('code', 'super_admin')->value('id'), 'user_id' => $superPsy->user_id, 'created_at' => now()]);
        $this->as($superPsy);
        $this->getJson("/api/v1/pro/clients/{$client->id}/notes")->assertNotFound();
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary")->assertNotFound();

        // The client cannot read notes about themselves.
        Sanctum::actingAs($client);
        $this->getJson("/api/v1/pro/clients/{$client->id}/notes")->assertForbidden();
    }

    public function test_notes_are_visible_only_to_their_author_and_encrypted_at_rest(): void
    {
        [$psyA, $client] = $this->pair();
        $psyB = $this->makePsychologist();
        $this->makeSession($psyB, $client, CarbonImmutable::now()->subDays(40), TherapySession::HELD, ['created_at' => now()->subDays(41)]);

        $this->as($psyA);
        $this->postJson("/api/v1/pro/clients/{$client->id}/notes", ['body' => 'Заметка А'])->assertCreated();
        $this->as($psyB);
        $this->postJson("/api/v1/pro/clients/{$client->id}/notes", ['body' => 'Заметка Б'])->assertCreated();

        $this->assertSame(['Заметка Б'], array_column($this->getJson("/api/v1/pro/clients/{$client->id}/notes")->json('data'), 'body'));
        $this->as($psyA);
        $this->assertSame(['Заметка А'], array_column($this->getJson("/api/v1/pro/clients/{$client->id}/notes")->json('data'), 'body'));

        $raw = DB::table('psychologist_notes')->pluck('body')->implode(' ');
        $this->assertStringNotContainsString('Заметка', $raw);
        $this->assertSame(0, AuditLog::query()->get()->filter(fn ($l) => str_contains(json_encode($l->toArray(), JSON_UNESCAPED_UNICODE), 'Заметка'))->count());
        $this->assertSame(0, DomainEvent::query()->get()->filter(fn ($e) => str_contains(json_encode($e->payload, JSON_UNESCAPED_UNICODE), 'Заметка'))->count());
    }

    public function test_note_can_be_linked_only_to_own_session_with_this_client(): void
    {
        [$psy, $client] = $this->pair();
        $otherClient = User::factory()->withRole('client')->create();
        $foreign = $this->makeSession($psy, $otherClient, CarbonImmutable::now()->subDays(3), TherapySession::HELD);
        $own = TherapySession::where('client_id', $client->id)->first();

        $this->as($psy);
        $this->postJson("/api/v1/pro/clients/{$client->id}/notes", ['body' => 'Текст', 'session_id' => $foreign->id])->assertJsonValidationErrors('session_id');
        $id = $this->postJson("/api/v1/pro/clients/{$client->id}/notes", ['body' => 'Текст', 'session_id' => $own->id])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/pro/clients/{$client->id}/notes/{$id}", ['body' => 'Новый текст'])->assertOk()->assertJsonPath('data.body', 'Новый текст');
        // The note of this client is not reachable through another client's card.
        $this->patchJson("/api/v1/pro/clients/{$otherClient->id}/notes/{$id}", ['body' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/pro/clients/{$client->id}/notes/{$id}")->assertOk();
        $this->assertSame(0, PsychologistNote::count());
    }

    public function test_diary_access_needs_a_booking_or_a_held_session(): void
    {
        $psy = $this->makePsychologist();
        $client = User::factory()->withRole('client')->create();
        $this->makeSession($psy, $client, CarbonImmutable::now()->addDays(3), TherapySession::CANCELLED_BY_CLIENT);
        $this->as($psy);
        // A cancellation that was never paid does not make a client.
        $this->getJson("/api/v1/pro/clients/{$client->id}")->assertNotFound();
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary")->assertNotFound();

        // A client who did not come: the card exists, the diary does not open.
        $this->makeSession($psy, $client, CarbonImmutable::now()->subDays(3), TherapySession::CLIENT_NO_SHOW, ['paid_at' => now()->subDays(4)]);
        $this->getJson("/api/v1/pro/clients/{$client->id}")->assertOk()->assertJsonPath('data.diary.available', false);
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary")->assertForbidden();

        // Booked with the psychologist: the dynamics open.
        $this->makeSession($psy, $client, CarbonImmutable::now()->addDays(5), TherapySession::BOOKED);
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary")->assertOk()->assertJsonPath('data.access.restricted', false);
    }

    public function test_psychologist_sees_mood_and_tags_but_never_the_client_note(): void
    {
        [$psy, $client] = $this->pair();
        DiaryEntry::create(['client_id' => $client->id, 'mood' => 2, 'note' => 'Никому не показывать', 'recorded_at' => now(), 'local_date' => now()->toDateString()]);

        $this->as($psy);
        $res = $this->getJson("/api/v1/pro/clients/{$client->id}/diary?period=week")->assertOk();
        $res->assertJsonPath('data.summary.entries', 1);
        $this->assertStringNotContainsString('Никому', $res->getContent());
        $this->assertStringNotContainsString('"note"', $res->getContent());
    }

    public function test_previous_psychologist_sees_only_diary_before_the_change(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00', 'UTC');
        $this->travelTo($now);
        [$old, $client] = $this->pair($now->subDays(20));
        $this->entry($client, $now->subDays(10), 2);
        $this->entry($client, $now->subDays(5), 3);
        $this->entry($client, $now->subDay(), 5);

        Outbox::record('book.psychologist.changed', null, ['client_id' => $client->id, 'psychologist_id' => $old->id, 'at' => $now->subDays(3)->toIso8601String()]);
        $card = ClientCard::where('psychologist_id', $old->id)->where('client_id', $client->id)->first();
        $this->assertNotNull($card->changed_psychologist_at);

        $new = $this->makePsychologist();
        $this->makeSession($new, $client, $now->addDays(2), TherapySession::BOOKED);

        $this->as($old);
        $res = $this->getJson("/api/v1/pro/clients/{$client->id}/diary?period=month")->assertOk();
        $res->assertJsonPath('data.access.restricted', true)->assertJsonPath('data.summary.entries', 2);
        $this->assertNotContains($now->subDay()->toDateString(), array_column($res->json('data.points'), 'date'));
        // Even an explicit period after the change shows nothing new.
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary?from={$now->subDays(2)->toDateString()}&to={$now->toDateString()}")
            ->assertOk()->assertJsonPath('data.summary.entries', 0);
        $this->getJson("/api/v1/pro/clients/{$client->id}")->assertOk()->assertJsonPath('data.status', 'changed')
            ->assertJsonPath('data.can_recommend', false);

        $this->as($new);
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary?period=month")->assertOk()
            ->assertJsonPath('data.access.restricted', false)->assertJsonPath('data.summary.entries', 3);

        // The event is processed idempotently: delivering it again changes nothing.
        $event = DomainEvent::where('name', 'book.psychologist.changed')->first();
        (new DeliverDomainEvent($event->id))->handle();
        $this->assertEquals($card->access_until, $card->fresh()->access_until);

        // The client books the previous psychologist again: the work resumed, the restriction is lifted.
        $this->travelTo($now->addHour());
        $this->makeSession($old, $client, $now->addDays(4), TherapySession::BOOKED);
        $this->as($old);
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary?period=month")->assertOk()
            ->assertJsonPath('data.access.restricted', false)->assertJsonPath('data.summary.entries', 3);
        $this->getJson("/api/v1/pro/clients/{$client->id}")->assertJsonPath('data.status', 'active');
    }

    public function test_finishing_work_closes_the_diary_window_and_emits_an_event(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00', 'UTC');
        $this->travelTo($now);
        [$psy, $client] = $this->pair($now->subDays(10));
        $upcoming = $this->makeSession($psy, $client, $now->addDays(3), TherapySession::BOOKED, ['created_at' => $now->subDays(9)]);
        $this->entry($client, $now->subDays(2), 4);

        $this->as($psy);
        $this->postJson("/api/v1/pro/clients/{$client->id}/finish")->assertStatus(422);
        $upcoming->forceFill(['status' => TherapySession::CANCELLED_BY_CLIENT])->save();

        $this->postJson("/api/v1/pro/clients/{$client->id}/finish")->assertOk()->assertJsonPath('data.status', 'finished');
        $event = DomainEvent::where('name', 'crm.work.finished')->first();
        $this->assertSame($psy->id, $event->payload['psychologist_id']);
        $this->assertSame($client->id, $event->payload['client_id']);
        $this->assertArrayHasKey('at', $event->payload);
        $this->postJson("/api/v1/pro/clients/{$client->id}/finish")->assertStatus(409);

        $this->travelTo($now->addDay());
        $this->entry($client, $now->addDay(), 1);
        $this->getJson("/api/v1/pro/clients/{$client->id}/diary?period=month")->assertOk()
            ->assertJsonPath('data.access.restricted', true)->assertJsonPath('data.summary.entries', 1);
        // The author still reads and writes own notes after the end of work.
        $this->postJson("/api/v1/pro/clients/{$client->id}/notes", ['body' => 'Итог работы'])->assertCreated();
    }

    public function test_notes_are_purged_after_the_retention_period(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00', 'UTC');
        $this->travelTo($now);
        $years = Settings::int('P-NOTES-RETENTION');

        // Finished long ago: purged.
        [$psy, $old] = $this->pair($now->subYears($years + 1));
        ClientCard::create(['psychologist_id' => $psy->id, 'client_id' => $old->id, 'work_finished_at' => $now->subYears($years)->subDay(), 'access_until' => $now->subYears($years)->subDay()]);
        $this->note($psy, $old, 'Старая заметка');

        // No mark, the last session long ago and nothing booked: the work ended with the last session.
        $quiet = User::factory()->withRole('client')->create();
        $this->makeSession($psy, $quiet, $now->subYears($years)->subMonth(), TherapySession::HELD, ['created_at' => $now->subYears($years)->subMonths(2)]);
        $this->note($psy, $quiet, 'Тихая заметка');

        // Recent work: kept.
        $recent = User::factory()->withRole('client')->create();
        $this->makeSession($psy, $recent, $now->subYear(), TherapySession::HELD, ['created_at' => $now->subYear()->subDay()]);
        $this->note($psy, $recent, 'Свежая заметка');

        $this->artisan('crm:purge-notes')->assertSuccessful();

        $this->assertSame(['Свежая заметка'], PsychologistNote::all()->pluck('body')->all());
        $logs = AuditLog::where('action', 'crm.notes.purged')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(1, $logs->first()->changes['count']);
        $this->assertStringNotContainsString('заметка', mb_strtolower(json_encode($logs->toArray(), JSON_UNESCAPED_UNICODE)));
    }

    public function test_account_anonymization_destroys_notes_by_and_about_the_user(): void
    {
        [$psy, $client] = $this->pair();
        $other = User::factory()->withRole('client')->create();
        $this->makeSession($psy, $other, CarbonImmutable::now()->subDays(2), TherapySession::HELD);
        $this->note($psy, $client, 'О клиенте');
        $this->note($psy, $other, 'О другом клиенте');

        Outbox::record('account.user.anonymized', $client, ['user_id' => $client->id]);
        $this->assertSame(['О другом клиенте'], PsychologistNote::all()->pluck('body')->all());

        Outbox::record('account.user.anonymized', null, ['user_id' => $psy->user_id]);
        $this->assertSame(0, PsychologistNote::count());

        $logs = AuditLog::where('action', 'crm.notes.destroyed')->get();
        $this->assertCount(2, $logs);
        $this->assertStringNotContainsString('клиент', mb_strtolower(json_encode($logs->pluck('changes'), JSON_UNESCAPED_UNICODE)));
    }

    /** @return array{0: Psychologist, 1: User} psychologist and client with a held session */
    private function pair(?CarbonImmutable $heldAt = null): array
    {
        $psy = $this->makePsychologist();
        $client = User::factory()->withRole('client')->create(['name' => 'Мария']);
        $start = $heldAt ?? CarbonImmutable::now()->subDays(5);
        $this->makeSession($psy, $client, $start, TherapySession::HELD, ['created_at' => $start->subDays(2), 'paid_at' => $start->subDay()]);

        return [$psy, $client->fresh()];
    }

    private function as(Psychologist $psy): void
    {
        Sanctum::actingAs(User::findOrFail($psy->user_id));
    }

    private function entry(User $client, CarbonImmutable $at, int $mood): void
    {
        DiaryEntry::create(['client_id' => $client->id, 'mood' => $mood, 'recorded_at' => $at, 'local_date' => $at->setTimezone('Europe/Moscow')->toDateString()]);
    }

    private function note(Psychologist $psy, User $client, string $body): void
    {
        PsychologistNote::create(['psychologist_id' => $psy->id, 'client_id' => $client->id, 'body' => $body]);
    }
}
