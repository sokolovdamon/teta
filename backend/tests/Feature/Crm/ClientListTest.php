<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** PRO-05: the list of clients and the card. */
class ClientListTest extends TestCase
{
    use CreatesPsychologists;

    public function test_list_contains_clients_with_sessions_except_never_paid_cancellations(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00', 'UTC');
        $this->travelTo($now);
        $psy = $this->makePsychologist();

        $active = $this->client('Анна');
        $this->makeSession($psy, $active, $now->subDays(7), TherapySession::HELD);
        $this->makeSession($psy, $active, $now->addDays(2), TherapySession::PAID, ['paid_at' => $now]);

        $past = $this->client('Борис');
        $this->makeSession($psy, $past, $now->subDays(30), TherapySession::HELD);

        $paidCancel = $this->client('Вера');
        $this->makeSession($psy, $paidCancel, $now->subDays(1), TherapySession::CANCELLED_BY_CLIENT, ['paid_at' => $now->subDays(2)]);

        $neverPaid = $this->client('Глеб');
        $this->makeSession($psy, $neverPaid, $now->addDays(1), TherapySession::CANCELLED_BY_CLIENT);

        $partner = $this->client('Дарья');
        $this->makeSession($psy, $active, $now->subDays(14), TherapySession::HELD, ['format' => 'pair', 'partner_user_id' => $partner->id]);

        $otherPsy = $this->makePsychologist();
        $this->makeSession($otherPsy, $this->client('Евгений'), $now->subDays(3), TherapySession::HELD);

        $this->as($psy);
        $res = $this->getJson('/api/v1/pro/clients')->assertOk();
        $names = array_column($res->json('data'), 'name');
        $this->assertSame('Анна', $names[0]);
        $this->assertEqualsCanonicalizing(['Анна', 'Борис', 'Вера', 'Дарья'], $names);
        $this->assertSame(4, $res->json('meta.total'));

        $first = $res->json('data.0');
        $this->assertSame('active', $first['status']);
        $this->assertSame(2, $first['held_count']);
        $this->assertSame(1, $first['upcoming_count']);
        $this->assertArrayNotHasKey('email', $first);
        $this->assertArrayNotHasKey('last_name', $first);

        $this->assertSame(['Борис'], array_column($this->getJson('/api/v1/pro/clients?search=бор')->json('data'), 'name'));
        $this->assertEqualsCanonicalizing(['Борис', 'Вера', 'Дарья'], array_column($this->getJson('/api/v1/pro/clients?status=no_upcoming')->json('data'), 'name'));
        $this->getJson('/api/v1/pro/clients?status=unknown')->assertJsonValidationErrors('status');
    }

    public function test_card_shows_sessions_with_this_psychologist_only_and_requests_from_booking(): void
    {
        $psy = $this->makePsychologist();
        $other = $this->makePsychologist();
        $client = $this->client('Мария');
        $requests = ClientRequest::where('format', 'individual')->orderBy('carousel_sort')->limit(2)->pluck('id')->all();
        $this->makeSession($psy, $client, CarbonImmutable::now()->subDays(7), TherapySession::HELD, ['client_request_ids' => $requests]);
        $this->makeSession($other, $client, CarbonImmutable::now()->subDays(3), TherapySession::HELD, ['client_request_ids' => [ClientRequest::where('slug', 'son')->value('id')]]);

        $this->as($psy);
        $card = $this->getJson("/api/v1/pro/clients/{$client->id}")->assertOk();
        $card->assertJsonPath('data.name', 'Мария')->assertJsonPath('data.diary.available', true)->assertJsonPath('data.can_finish', true);
        $this->assertEqualsCanonicalizing($requests, array_column($card->json('data.requests'), 'id'));

        $sessions = $this->getJson("/api/v1/pro/clients/{$client->id}/sessions")->assertOk();
        $this->assertCount(1, $sessions->json('data'));
        $this->assertSame('held', $sessions->json('data.0.status'));
    }

    public function test_clients_section_is_for_psychologists(): void
    {
        $this->actingAsRole('client');
        $this->getJson('/api/v1/pro/clients')->assertForbidden();
        $this->actingAsRole('admin');
        $this->getJson('/api/v1/pro/clients')->assertForbidden();
    }

    private function client(string $name): User
    {
        return User::factory()->withRole('client')->create(['name' => $name]);
    }

    private function as(Psychologist $psy): void
    {
        Sanctum::actingAs(User::findOrFail($psy->user_id));
    }
}
