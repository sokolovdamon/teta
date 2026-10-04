<?php

namespace Tests\Feature\ClientHome;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Events\Outbox;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** CL-02: one request for the client's cabinet home. */
class ClientHomeTest extends TestCase
{
    use CreatesPsychologists;

    public function test_home_for_a_new_client_has_empty_blocks(): void
    {
        $this->actingAsRole('client');
        $res = $this->getJson('/api/v1/client/home')->assertOk();
        $res->assertJsonPath('data.next_session', null)
            ->assertJsonPath('data.diary.show_prompt', true)
            ->assertJsonPath('data.recommendations.unread_count', 0)
            ->assertJsonPath('data.psychologists', [])
            ->assertJsonPath('data.room_window.open_before_min', Settings::int('P-ROOM-OPEN'))
            ->assertJsonPath('data.room_window.close_after_min', Settings::int('P-ROOM-CLOSE'));
    }

    public function test_home_shows_next_session_with_room_window_recommendations_and_psychologist(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00', 'UTC');
        $this->travelTo($now);
        $psy = $this->makePsychologist();
        $client = User::factory()->withRole('client')->create();
        $held = $this->makeSession($psy, $client, $now->subDays(2), TherapySession::HELD, ['created_at' => $now->subDays(4)]);
        $next = $this->makeSession($psy, $client, $now->addMinutes(5), TherapySession::PAID, ['paid_at' => $now->subHours(12)]);
        $this->makeSession($psy, $client, $now->addDays(7), TherapySession::BOOKED);

        $reco = new Recommendation(['psychologist_id' => $psy->id, 'client_id' => $client->id, 'session_id' => $held->id, 'type' => 'task', 'title' => 'Задание', 'window_until' => $now->addDays(5), 'sent_at' => $now]);
        $reco->forceFill(['status' => Recommendation::SENT])->save();
        DiaryEntry::create(['client_id' => $client->id, 'mood' => 4, 'recorded_at' => $now, 'local_date' => $now->setTimezone('Europe/Moscow')->toDateString()]);

        Sanctum::actingAs($client);
        $res = $this->getJson('/api/v1/client/home')->assertOk();
        $res->assertJsonPath('data.next_session.id', $next->id)
            ->assertJsonPath('data.next_session.is_paid', true)
            ->assertJsonPath('data.next_session.room.url', "/room/session/{$next->id}")
            ->assertJsonPath('data.next_session.room.opens_at', $now->addMinutes(5)->subMinutes(Settings::int('P-ROOM-OPEN'))->toIso8601String())
            ->assertJsonPath('data.next_session.psychologist.slug', $psy->slug)
            ->assertJsonPath('data.diary.show_prompt', false)
            ->assertJsonPath('data.diary.today_mood', 4)
            ->assertJsonPath('data.recommendations.unread_count', 1)
            ->assertJsonPath('data.recommendations.items.0.title', 'Задание')
            ->assertJsonPath('data.psychologists.0.psychologist_id', $psy->id)
            ->assertJsonPath('data.psychologists.0.upcoming_sessions', 2);

        // During the session (room still open after the end) the same session stays "next".
        $this->travelTo($now->addMinutes(60));
        $this->getJson('/api/v1/client/home')->assertJsonPath('data.next_session.id', $next->id);
    }

    public function test_psychologist_the_client_changed_away_from_is_not_listed(): void
    {
        $psy = $this->makePsychologist();
        $client = User::factory()->withRole('client')->create();
        $this->makeSession($psy, $client, CarbonImmutable::now()->subDays(3), TherapySession::HELD, ['created_at' => now()->subDays(5)]);
        Outbox::record('book.psychologist.changed', null, ['client_id' => $client->id, 'psychologist_id' => $psy->id, 'at' => now()->toIso8601String()]);

        Sanctum::actingAs($client);
        $this->getJson('/api/v1/client/home')->assertOk()->assertJsonPath('data.psychologists', []);
    }

    public function test_home_is_for_clients(): void
    {
        $this->actingAsRole('psychologist');
        $this->getJson('/api/v1/client/home')->assertForbidden();
    }
}
