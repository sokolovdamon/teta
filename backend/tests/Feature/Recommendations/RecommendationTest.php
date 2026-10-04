<?php

namespace Tests\Feature\Recommendations;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Models\MailMessage;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Events\Outbox;
use App\Support\Settings\Settings;
use App\Support\StateMachine\StateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** RECO (PRO-07, CL-05, DM-12). */
class RecommendationTest extends TestCase
{
    use CreatesPsychologists;

    public function test_recommendation_lifecycle_with_files_links_and_content_free_letter(): void
    {
        Storage::fake('local');
        [$psy, $client, $session] = $this->heldSession();

        $this->as($psy);
        $fileId = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->create('practice.pdf', 120, 'application/pdf'), 'purpose' => 'recommendation'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $id = $this->postJson('/api/v1/pro/recommendations', [
            'session_id' => $session->id, 'type' => 'exercise', 'title' => 'Дыхание 4-7-8',
            'body' => 'Повторяйте упражнение перед сном три раза.', 'due_date' => now()->addWeek()->toDateString(),
            'links' => [['url' => 'https://example.org/breath', 'title' => 'Видео'], ['url' => '/client/materials/dyhanie', 'kb_material_id' => '0195f0f0-0000-7000-8000-000000000001']],
            'file_ids' => [$fileId],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonCount(1, 'data.files')->assertJsonCount(2, 'data.links')->json('data.id');

        // The client does not see drafts.
        Sanctum::actingAs($client);
        $this->getJson('/api/v1/client/recommendations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/client/recommendations/{$id}")->assertNotFound();

        $this->as($psy);
        $this->patchJson("/api/v1/pro/recommendations/{$id}", ['title' => 'Дыхание перед сном'])->assertOk();
        $this->postJson("/api/v1/pro/recommendations/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->patchJson("/api/v1/pro/recommendations/{$id}", ['title' => 'Другое'])->assertStatus(409);

        // Letter and centre item: the fact and a link, never the content.
        $mail = MailMessage::where('user_id', $client->id)->where('template_code', 'reco.recommendation_sent')->first();
        $this->assertNotNull($mail);
        $this->assertStringNotContainsString('Дыхание', $mail->subject);
        $item = UserNotification::where('user_id', $client->id)->first();
        $this->assertSame("/client/recommendations/{$id}", $item->link);
        $this->assertStringNotContainsString('Дыхание', $item->title);

        Sanctum::actingAs($client);
        $this->getJson('/api/v1/client/recommendations')->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.status', 'sent');
        $open = $this->getJson("/api/v1/client/recommendations/{$id}")->assertOk()->assertJsonPath('data.status', 'viewed');
        $this->assertStringContainsString('/files/', $open->json('data.files.0.url'));
        $this->assertSame($psy->slug, $open->json('data.psychologist.slug'));
        $this->getJson('/api/v1/client/recommendations')->assertJsonPath('meta.unread', 0);

        // After viewing it can no longer be revoked.
        $this->as($psy);
        $this->postJson("/api/v1/pro/recommendations/{$id}/revoke")->assertStatus(409);

        Sanctum::actingAs($client);
        $this->postJson("/api/v1/client/recommendations/{$id}/done")->assertOk()->assertJsonPath('data.status', 'done');
        $this->getJson('/api/v1/client/recommendations?status=done')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/client/recommendations/{$id}/undo")->assertOk()->assertJsonPath('data.status', 'viewed');

        $history = StateTransition::where('model_type', 'recommendation')->where('model_id', $id)->pluck('to')->all();
        $this->assertEqualsCanonicalizing(['draft', 'sent', 'viewed', 'done', 'viewed'], $history);
    }

    public function test_revoke_before_viewing_hides_it_from_the_client(): void
    {
        [$psy, $client, $session] = $this->heldSession();
        $this->as($psy);
        $id = $this->postJson('/api/v1/pro/recommendations', ['session_id' => $session->id, 'type' => 'task', 'title' => 'Дневник мыслей', 'send' => true])
            ->assertCreated()->assertJsonPath('data.status', 'sent')->json('data.id');

        $this->postJson("/api/v1/pro/recommendations/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());

        Sanctum::actingAs($client);
        $this->getJson('/api/v1/client/recommendations')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/client/recommendations/{$id}")->assertNotFound();
        $this->postJson("/api/v1/client/recommendations/{$id}/done")->assertNotFound();
    }

    public function test_only_held_sessions_within_the_window(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 12:00', 'UTC');
        $this->travelTo($now);
        $psy = $this->makePsychologist();
        $client = User::factory()->withRole('client')->create();
        $days = Settings::int('P-RECO-WINDOW');
        $old = $this->makeSession($psy, $client, $now->subDays($days + 1), TherapySession::HELD);
        $upcoming = $this->makeSession($psy, $client, $now->addDays(2), TherapySession::PAID);
        $fresh = $this->makeSession($psy, $client, $now->subDays(1), TherapySession::HELD);

        $this->as($psy);
        $payload = ['type' => 'material', 'title' => 'Статья о сне'];
        $this->postJson('/api/v1/pro/recommendations', [...$payload, 'session_id' => $old->id])->assertJsonValidationErrors('session_id');
        $this->postJson('/api/v1/pro/recommendations', [...$payload, 'session_id' => $upcoming->id])->assertJsonValidationErrors('session_id');
        $id = $this->postJson('/api/v1/pro/recommendations', [...$payload, 'session_id' => $fresh->id])->assertCreated()->json('data.id');

        $sessions = $this->getJson("/api/v1/pro/clients/{$client->id}/recommendations")->assertOk()->json('sessions');
        $this->assertSame([$fresh->id], array_column($sessions, 'id'));

        // The window copied at creation also limits sending a draft.
        $this->travelTo($now->addDays($days));
        $this->postJson("/api/v1/pro/recommendations/{$id}/send")->assertJsonValidationErrors('session_id');
        $this->assertSame(Recommendation::DRAFT, Recommendation::find($id)->status);
    }

    public function test_recommendations_are_private_to_author_and_recipient(): void
    {
        [$psy, $client, $session] = $this->heldSession();
        $this->as($psy);
        $id = $this->postJson('/api/v1/pro/recommendations', ['session_id' => $session->id, 'type' => 'task', 'title' => 'Задание', 'send' => true])->json('data.id');

        $other = $this->makePsychologist();
        $this->as($other);
        $this->getJson("/api/v1/pro/recommendations/{$id}")->assertNotFound();
        $this->postJson("/api/v1/pro/recommendations/{$id}/revoke")->assertNotFound();
        $this->getJson("/api/v1/pro/clients/{$client->id}/recommendations")->assertNotFound();
        $this->postJson('/api/v1/pro/recommendations', ['session_id' => $session->id, 'type' => 'task', 'title' => 'Чужая сессия'])->assertJsonValidationErrors('session_id');

        $this->actingAsRole('client');
        $this->getJson("/api/v1/client/recommendations/{$id}")->assertNotFound();
        $this->actingAsRole('admin');
        $this->getJson("/api/v1/pro/recommendations/{$id}")->assertForbidden();
        $this->getJson("/api/v1/client/recommendations/{$id}")->assertForbidden();
        $this->actingAsRole('super_admin');
        $this->getJson("/api/v1/pro/recommendations/{$id}")->assertForbidden();
        $this->getJson("/api/v1/client/recommendations/{$id}")->assertNotFound();
    }

    public function test_files_must_be_own_recommendation_uploads(): void
    {
        Storage::fake('local');
        [$psy, , $session] = $this->heldSession();
        $other = $this->makePsychologist();
        $this->as($other);
        $foreignFile = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), 'purpose' => 'recommendation'], ['Accept' => 'application/json'])->json('data.id');

        $this->as($psy);
        $this->postJson('/api/v1/pro/recommendations', ['session_id' => $session->id, 'type' => 'task', 'title' => 'С файлом', 'file_ids' => [$foreignFile]])
            ->assertJsonValidationErrors('file_ids');
        $this->postJson('/api/v1/pro/recommendations', ['session_id' => $session->id, 'type' => 'task', 'title' => 'Ссылка', 'links' => [['url' => 'javascript:alert(1)']]])
            ->assertJsonValidationErrors('links.0.url');
    }

    public function test_previous_psychologist_cannot_send_after_the_client_changed(): void
    {
        [$psy, $client, $session] = $this->heldSession();
        $this->as($psy);
        $id = $this->postJson('/api/v1/pro/recommendations', ['session_id' => $session->id, 'type' => 'task', 'title' => 'Черновик'])->json('data.id');

        Outbox::record('book.psychologist.changed', null, ['client_id' => $client->id, 'psychologist_id' => $psy->id, 'at' => now()->toIso8601String()]);
        $this->postJson("/api/v1/pro/recommendations/{$id}/send")->assertStatus(409);
        $this->deleteJson("/api/v1/pro/recommendations/{$id}")->assertOk();
    }

    public function test_recommendations_of_an_anonymized_client_are_destroyed(): void
    {
        [$psy, $client, $session] = $this->heldSession();
        $this->as($psy);
        $this->postJson('/api/v1/pro/recommendations', ['session_id' => $session->id, 'type' => 'task', 'title' => 'Задание', 'send' => true])->assertCreated();

        Outbox::record('account.user.anonymized', null, ['user_id' => $client->id]);
        $this->assertSame(0, Recommendation::count());
    }

    /** @return array{0: Psychologist, 1: User, 2: TherapySession} */
    private function heldSession(): array
    {
        $psy = $this->makePsychologist();
        $client = User::factory()->withRole('client')->create();
        $session = $this->makeSession($psy, $client, CarbonImmutable::now()->subDays(1), TherapySession::HELD, ['created_at' => now()->subDays(3)]);

        return [$psy, $client->fresh(), $session];
    }

    private function as(Psychologist $psy): void
    {
        Sanctum::actingAs(User::findOrFail($psy->user_id));
    }
}
