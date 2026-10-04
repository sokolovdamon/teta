<?php

namespace Tests\Feature\Psychologists;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Dictionaries\Models\Approach;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPsychologistProfiles;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** PRO-02, BR-PSY-05 (pending changes), DEC-19/DEC-55 (prices), DEC-44 (video card), BR-PSY-06 (work status). */
class ProfileModerationTest extends TestCase
{
    use BuildsPsychologistProfiles, CreatesPsychologists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
    }

    public function test_draft_profile_edits_apply_directly(): void
    {
        $p = $this->draftPsychologist();
        Sanctum::actingAs($p->user);

        $this->patchJson('/api/v1/pro/profile', $this->completeProfilePayload())->assertOk()
            ->assertJsonPath('data.pending', null)
            ->assertJsonPath('data.requires_moderation', false)
            ->assertJsonPath('data.missing', []);

        $p = $p->fresh();
        $this->assertSame('Помогаю справляться с тревогой и выгоранием', $p->headline);
        $this->assertCount(2, $p->approaches);
        $this->assertSame('Помогает замечать автоматические мысли.', $p->approaches->first()->pivot->explanation);
        $this->assertSame('economy', $p->priceCategory->code);
    }

    public function test_approved_profile_changes_wait_for_moderation(): void
    {
        $p = $this->completePsychologist(['headline' => 'Старый заголовок профиля']);
        $act = Approach::where('slug', 'act')->firstOrFail();
        Sanctum::actingAs($p->user);

        $this->patchJson('/api/v1/pro/profile', [
            'headline' => 'Новый заголовок профиля',
            'approaches' => [['id' => $act->id, 'explanation' => 'Новое пояснение']],
        ])->assertOk()
            ->assertJsonPath('result.pending', ['headline', 'approaches'])
            ->assertJsonPath('data.values.headline', 'Новый заголовок профиля')
            ->assertJsonPath('data.pending.fields', ['headline', 'approaches']);

        // The site keeps showing the published version (BR-PSY-05).
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertOk()
            ->assertJsonPath('data.headline', 'Старый заголовок профиля')
            ->assertJsonPath('data.approaches.0.slug', 'kpt');
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.profile.changes_submitted', 'aggregate_id' => $p->id]);

        $admin = $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/psychologists?moderation=changes')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/psychologists/{$p->id}")->assertOk()
            ->assertJsonPath('data.pending.diff.0.field', 'headline')
            ->assertJsonPath('data.pending.diff.0.before', 'Старый заголовок профиля')
            ->assertJsonPath('data.pending.diff.0.after', 'Новый заголовок профиля')
            ->assertJsonPath('data.pending.diff.1.field', 'approaches')
            ->assertJsonPath('data.pending.diff.1.after.0.title', $act->title);

        $this->postJson("/api/v1/admin/psychologists/{$p->id}/changes/approve")->assertOk()->assertJsonPath('data.pending', null);
        $this->getJson("/api/v1/psychologists/{$p->slug}")
            ->assertJsonPath('data.headline', 'Новый заголовок профиля')
            ->assertJsonPath('data.approaches.0.slug', 'act')
            ->assertJsonPath('data.approaches.0.explanation', 'Новое пояснение');
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychologist.changes_approved', 'subject_id' => $p->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.profile_changes_approved']);
        $this->assertSame('approved', $p->fresh()->qualification_status);
    }

    public function test_rejected_changes_keep_the_published_version(): void
    {
        $p = $this->completePsychologist(['about' => str_repeat('Опубликованный текст о себе. ', 5)]);
        Sanctum::actingAs($p->user);
        $this->patchJson('/api/v1/pro/profile', ['about' => str_repeat('Черновик нового текста. ', 6)])->assertOk();

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/changes/reject")->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/changes/reject", ['comment' => 'Уберите контакты из текста'])->assertOk();

        $p = $p->fresh();
        $this->assertNull($p->pending_changes);
        $this->assertStringStartsWith('Опубликованный текст', $p->about);
        $this->assertSame('Уберите контакты из текста', $p->pending_review_comment);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.profile_changes_rejected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychologist.changes_rejected', 'subject_id' => $p->id]);
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/changes/approve")->assertUnprocessable();
    }

    public function test_reverting_a_field_clears_it_from_pending(): void
    {
        $p = $this->completePsychologist(['experience_years' => 7]);
        Sanctum::actingAs($p->user);
        $this->patchJson('/api/v1/pro/profile', ['experience_years' => 8])->assertJsonPath('data.pending.fields', ['experience_years']);
        $this->patchJson('/api/v1/pro/profile', ['experience_years' => 7])->assertJsonPath('data.pending', null);
        $this->assertNull($p->fresh()->pending_changes);
    }

    public function test_prices_apply_at_once_with_category_history_and_do_not_touch_bookings(): void
    {
        $p = $this->completePsychologist(['price_individual' => 400000]);
        $client = User::factory()->withRole('client')->create();
        $session = $this->makeSession($p, $client, CarbonImmutable::now()->addDays(3));
        Sanctum::actingAs($p->user);

        $this->patchJson('/api/v1/pro/profile', ['price_individual' => 600000])->assertOk()
            ->assertJsonPath('data.pending', null)
            ->assertJsonPath('data.prices.price_category.code', 'premium')
            ->assertJsonPath('data.price_history.0.price_individual', 600000);

        $this->assertSame(600000, $p->fresh()->price_individual);
        $this->assertSame('premium', $p->fresh()->priceCategory->code);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.profile.price_changed', 'aggregate_id' => $p->id]);
        $this->assertSame(400000, (int) TherapySession::find($session->id)->price);

        $this->patchJson('/api/v1/pro/profile', ['price_individual' => 1000])->assertUnprocessable()->assertJsonValidationErrors('price_individual');
        $this->patchJson('/api/v1/pro/profile', ['works_individual' => false, 'works_pair' => false])->assertUnprocessable();
        $this->patchJson('/api/v1/pro/profile', ['works_pair' => true])->assertUnprocessable()->assertJsonValidationErrors('price_pair');
        $this->patchJson('/api/v1/pro/profile', ['works_pair' => true, 'price_pair' => 700000])->assertOk();
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.price_pair', 700000)->assertJsonPath('data.works_pair', true);
    }

    public function test_photo_of_approved_psychologist_is_moderated(): void
    {
        $p = $this->completePsychologist();
        Sanctum::actingAs($p->user);

        $this->postJson('/api/v1/pro/profile/photo', ['file' => UploadedFile::fake()->image('me.jpg', 400, 400)])->assertOk()
            ->assertJsonPath('data.pending.fields', ['photo_file_id'])
            ->assertJsonPath('data.published_photo_url', null)
            ->assertJsonPath('data.photo_url', fn ($url) => is_string($url) && $url !== '');
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.photo_url', null);

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/changes/approve")->assertOk();
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.photo_url', fn ($url) => is_string($url));
    }

    public function test_video_card_has_separate_moderation(): void
    {
        $p = $this->completePsychologist();
        Sanctum::actingAs($p->user);

        $this->postJson('/api/v1/pro/profile/video', ['file' => UploadedFile::fake()->create('card.mp4', 2048, 'video/mp4'), 'duration' => 200])
            ->assertUnprocessable()->assertJsonValidationErrors('duration');
        $this->postJson('/api/v1/pro/profile/video', ['file' => UploadedFile::fake()->create('card.mp4', 2048, 'video/mp4'), 'duration' => 60])
            ->assertOk()->assertJsonPath('data.video.status', 'pending');
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.video_url', null)->assertJsonPath('data.has_video', false);

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/psychologists?moderation=video')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/video/approve")->assertOk()->assertJsonPath('data.video.status', 'approved');
        $approvedUrl = $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.has_video', true)->json('data.video_url');
        $this->assertNotNull($approvedUrl);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.video_approved']);

        // A new upload waits for moderation; the approved one stays on the site; rejection keeps it there.
        Sanctum::actingAs($p->user->fresh());
        $this->postJson('/api/v1/pro/profile/video', ['file' => UploadedFile::fake()->create('new.mp4', 1024, 'video/mp4'), 'duration' => 45])->assertOk();
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.video_url', $approvedUrl);

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/video/reject", [])->assertUnprocessable();
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/video/reject", ['comment' => 'Плохой звук'])->assertOk()
            ->assertJsonPath('data.video.status', 'rejected')->assertJsonPath('data.video.comment', 'Плохой звук');
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.video_url', $approvedUrl);
        $this->assertSame('approved', $p->fresh()->qualification_status);
        $this->assertTrue($p->fresh()->isBookable());

        // Removing the video card hides it.
        Sanctum::actingAs($p->user->fresh());
        $this->deleteJson('/api/v1/pro/profile/video')->assertOk()->assertJsonPath('data.video.status', 'none');
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.video_url', null);
    }

    public function test_video_size_limit_comes_from_settings(): void
    {
        Settings::set('P-VIDEO-CARD-LIMITS', ['seconds' => 90, 'megabytes' => 1]);
        $p = $this->completePsychologist();
        Sanctum::actingAs($p->user);
        $this->postJson('/api/v1/pro/profile/video', ['file' => UploadedFile::fake()->create('big.mp4', 2048, 'video/mp4'), 'duration' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/pro/profile/video', ['file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'), 'duration' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_pause_resume_and_admin_block(): void
    {
        $p = $this->completePsychologist();
        Sanctum::actingAs($p->user);

        $this->postJson('/api/v1/pro/profile/pause', ['reason' => 'Отпуск по уходу'])->assertOk()->assertJsonPath('data.work_status', 'paused');
        $this->getJson('/api/v1/psychologists')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.work_status.paused', 'aggregate_id' => $p->id]);
        $this->postJson('/api/v1/pro/profile/resume')->assertOk()->assertJsonPath('data.work_status', 'active');
        $this->getJson('/api/v1/psychologists')->assertJsonCount(1, 'data');

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/block")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/block", ['reason' => 'Жалобы клиентов, идёт проверка'])->assertOk()
            ->assertJsonPath('data.work_status', 'blocked');
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.work_status.blocked', 'aggregate_id' => $p->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychologist.blocked', 'subject_id' => $p->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.work_status_blocked']);
        $this->getJson('/api/v1/psychologists')->assertJsonCount(0, 'data');

        Sanctum::actingAs($p->user->fresh());
        $this->postJson('/api/v1/pro/profile/resume')->assertUnprocessable();
        $this->postJson('/api/v1/pro/profile/pause')->assertUnprocessable();

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/unblock")->assertOk()->assertJsonPath('data.work_status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychologist.unblocked', 'subject_id' => $p->id]);
        $this->getJson('/api/v1/psychologists')->assertJsonCount(1, 'data');
    }

    public function test_supervisor_without_psychologist_profile_gets_404_and_super_admin_card_works(): void
    {
        $this->actingAsRole('supervisor');
        $this->getJson('/api/v1/pro/profile')->assertNotFound();

        $p = $this->completePsychologist();
        $this->actingAsRole('super_admin');
        $this->getJson("/api/v1/admin/psychologists/{$p->id}")->assertOk()
            ->assertJsonPath('data.activity.activity_status', 'active_met')
            ->assertJsonPath('data.schedule.intervals', 7)
            ->assertJsonPath('data.missing', []);
    }
}
