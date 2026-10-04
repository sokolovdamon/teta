<?php

namespace Tests\Feature\Psychologists;

use App\Models\User;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\QualificationDocument;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\RbacService;
use App\Support\Events\DomainEvent;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPsychologistProfiles;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** PRO-01 / ADM-03, ST-08, SEQ-12. */
class QualificationFlowTest extends TestCase
{
    use BuildsPsychologistProfiles, CreatesPsychologists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
    }

    public function test_draft_shows_checklist_and_uploads_documents(): void
    {
        $p = $this->draftPsychologist();
        Sanctum::actingAs($p->user);

        $this->getJson('/api/v1/pro/qualification')->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.missing', fn ($m) => in_array('documents', array_column($m, 'code'), true) && in_array('about', array_column($m, 'code'), true));

        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'diploma', 'title' => 'Диплом психолога', 'institution' => 'МГУ', 'year' => 2010])
            ->assertCreated()
            ->assertJsonPath('data.documents.0.status', 'pending')
            ->assertJsonPath('data.documents.0.can_delete', true)
            ->assertJsonPath('data.documents.0.file.mime_type', 'application/pdf');

        // Unsupported file type is rejected by FILES.
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload'), 'kind' => 'diploma', 'title' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'passport', 'title' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');

        $doc = $p->documents()->first();
        $this->deleteJson("/api/v1/pro/qualification/documents/{$doc->id}")->assertOk();
        $this->assertDatabaseMissing('qualification_documents', ['id' => $doc->id]);
    }

    public function test_submission_requires_profile_fields_and_education_document(): void
    {
        $p = $this->draftPsychologist();
        Sanctum::actingAs($p->user);

        $this->postJson('/api/v1/pro/qualification/submit')->assertUnprocessable()->assertJsonValidationErrors('qualification');

        $this->patchJson('/api/v1/pro/profile', $this->completeProfilePayload())->assertOk();
        // A certificate is not a psychological education document.
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'certificate', 'title' => 'Сертификат'])->assertCreated();
        $this->postJson('/api/v1/pro/qualification/submit')->assertUnprocessable()
            ->assertJsonPath('errors.qualification.0', fn ($m) => str_contains($m, 'образовании'));

        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'retraining', 'title' => 'Переподготовка'])->assertCreated();
        $this->postJson('/api/v1/pro/qualification/submit')->assertOk()->assertJsonPath('data.status', 'in_review');
    }

    public function test_submission_needs_a_verified_email(): void
    {
        $user = User::factory()->unverified()->withRole('psychologist')->create();
        $p = $this->draftPsychologist($user);
        Sanctum::actingAs($p->user);
        $this->postJson('/api/v1/pro/qualification/submit')->assertForbidden()->assertJsonPath('code', 'email_not_verified');
    }

    public function test_full_flow_reject_resubmit_approve_and_publish(): void
    {
        $verifier = $this->userWithRole('admin');
        $p = $this->draftPsychologist();
        Sanctum::actingAs($p->user);
        $this->patchJson('/api/v1/pro/profile', $this->completeProfilePayload())->assertOk();
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'diploma', 'title' => 'Диплом'])->assertCreated();
        $this->putJson('/api/v1/pro/schedule/intervals', ['intervals' => [['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '14:00']]])->assertOk();
        $this->assertFalse($p->fresh()->is_published, 'not published before approval');

        $this->postJson('/api/v1/pro/qualification/submit')->assertOk();
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.qualification.submitted', 'aggregate_id' => $p->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.qualification_submitted']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $verifier->id, 'template_code' => 'psy.qualification_new_submission']);

        // Documents are frozen while in review.
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'diploma', 'title' => 'Ещё'])->assertUnprocessable();
        $doc = $p->documents()->first();
        $this->deleteJson("/api/v1/pro/qualification/documents/{$doc->id}")->assertUnprocessable();
        $this->postJson('/api/v1/pro/qualification/submit')->assertUnprocessable();

        // Rejection needs a comment.
        Sanctum::actingAs($verifier);
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/qualification/reject", [])->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/qualification/reject", ['comment' => 'Диплом нечитаемый, загрузите скан лучшего качества', 'document_ids' => [$doc->id]])
            ->assertOk()->assertJsonPath('data.qualification_status', 'rejected');
        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.qualification.rejected', 'aggregate_id' => $p->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.qualification_rejected']);
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-03', 'action' => 'psychologist.qualification_rejected', 'subject_id' => $p->id]);

        // The psychologist replaces the document and resubmits (unlimited, BR-PSY-03).
        Sanctum::actingAs($p->user->fresh());
        $this->getJson('/api/v1/pro/qualification')->assertOk()
            ->assertJsonPath('data.comment', 'Диплом нечитаемый, загрузите скан лучшего качества')
            ->assertJsonPath('data.history', fn ($h) => count($h) === 2 && $h[1]['to'] === 'rejected' && $h[1]['comment'] !== null);
        $this->postJson('/api/v1/pro/qualification/submit')->assertUnprocessable(); // the only diploma is rejected
        $this->deleteJson("/api/v1/pro/qualification/documents/{$doc->id}")->assertOk();
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'diploma', 'title' => 'Диплом, новый скан'])->assertCreated();
        $this->postJson('/api/v1/pro/qualification/submit')->assertOk()->assertJsonPath('data.status', 'in_review');

        Sanctum::actingAs($verifier);
        $this->getJson("/api/v1/admin/psychologists/{$p->id}")->assertOk()
            ->assertJsonPath('data.documents.0.file.url', fn ($url) => str_contains($url, 'signature='));
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychologist.documents_viewed', 'subject_id' => $p->id]);

        $this->postJson("/api/v1/admin/psychologists/{$p->id}/qualification/approve", ['comment' => 'Всё в порядке'])
            ->assertOk()->assertJsonPath('data.qualification_status', 'approved');

        $p = $p->fresh();
        $this->assertNotNull($p->qualified_at);
        $this->assertSame('grace', $p->activity_status);
        $this->assertTrue($p->is_published);
        $this->assertTrue($p->isBookable());
        $this->assertSame(['approved'], $p->documents()->pluck('status')->unique()->values()->all());
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.qualification.approved', 'aggregate_id' => $p->id]);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.profile.published', 'aggregate_id' => $p->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychologist.qualification_approved', 'subject_id' => $p->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.qualification_approved']);

        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_approval_without_intervals_does_not_publish(): void
    {
        $p = $this->draftPsychologist();
        Sanctum::actingAs($p->user);
        $this->patchJson('/api/v1/pro/profile', $this->completeProfilePayload())->assertOk();
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'diploma', 'title' => 'Диплом'])->assertCreated();
        $this->postJson('/api/v1/pro/qualification/submit')->assertOk();

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/qualification/approve")->assertOk();
        $this->assertFalse($p->fresh()->is_published);

        // The first working interval publishes the profile (BR-PSY-04).
        Sanctum::actingAs($p->user->fresh());
        $this->postJson('/api/v1/pro/schedule/intervals', ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00'])->assertCreated();
        $this->assertTrue($p->fresh()->is_published);
    }

    public function test_admin_permissions_are_enforced(): void
    {
        $p = $this->draftPsychologist();

        $this->actingAsRole('client');
        $this->getJson('/api/v1/admin/psychologists')->assertForbidden();
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/qualification/approve")->assertForbidden();

        Sanctum::actingAs($p->user);
        $this->getJson('/api/v1/admin/psychologists')->assertForbidden();

        $this->actingAsRole('client');
        $this->getJson('/api/v1/pro/profile')->assertForbidden();

        // A custom RBAC role that may only view psychologists.
        $viewer = Role::create(['code' => 'psy_viewer', 'title' => 'Просмотр психологов', 'is_system' => false]);
        app(RbacService::class)->setRolePermissions($viewer, ['admin.psychologists.view']);
        Sanctum::actingAs($this->userWithRole('psy_viewer'));
        $this->getJson('/api/v1/admin/psychologists')->assertOk();
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/qualification/approve")->assertForbidden();
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/block", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/changes/approve")->assertForbidden();
    }

    public function test_new_document_of_approved_psychologist_is_reviewed_separately(): void
    {
        $p = $this->completePsychologist();
        Sanctum::actingAs($p->user);
        $this->postJson('/api/v1/pro/qualification/documents', ['file' => $this->pdf(), 'kind' => 'certificate', 'title' => 'Сертификат по ЭФТ'])
            ->assertCreated()->assertJsonPath('data.status', 'approved');
        $doc = QualificationDocument::where('psychologist_id', $p->id)->firstOrFail();
        $this->assertSame('pending', $doc->status);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.qualification.document_added', 'aggregate_id' => $p->id]);

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/psychologists?moderation=documents')->assertOk()->assertJsonPath('data.0.id', $p->id);
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/documents/{$doc->id}/review", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/documents/{$doc->id}/review", ['status' => 'approved'])->assertOk();

        $this->assertSame('approved', $doc->fresh()->status);
        $this->assertSame('approved', $p->fresh()->qualification_status);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $p->user_id, 'template_code' => 'psy.document_approved']);

        // An approved document cannot be deleted.
        Sanctum::actingAs($p->user->fresh());
        $this->deleteJson("/api/v1/pro/qualification/documents/{$doc->id}")->assertUnprocessable();
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertJsonPath('data.documents.0.title', 'Сертификат по ЭФТ')
            ->assertJsonMissingPath('data.documents.0.url');
    }

    public function test_revoking_approved_qualification_unpublishes_profile(): void
    {
        $p = $this->completePsychologist();
        $doc = QualificationDocument::create([
            'psychologist_id' => $p->id, 'file_id' => $this->storedFile($p)->id, 'kind' => 'diploma', 'title' => 'Диплом', 'status' => 'approved',
        ]);

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/qualification/reject", ['comment' => 'Диплом признан недействительным', 'document_ids' => [$doc->id]])
            ->assertOk()->assertJsonPath('data.qualification_status', 'rejected')->assertJsonPath('data.is_published', false);

        $event = DomainEvent::where('name', 'psy.qualification.rejected')->where('aggregate_id', $p->id)->firstOrFail();
        $this->assertTrue($event->payload['revoked']);
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.profile.unpublished', 'aggregate_id' => $p->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'psychologist.qualification_revoked', 'subject_id' => $p->id]);
        $this->assertSame('rejected', $doc->fresh()->status);

        $this->getJson('/api/v1/psychologists')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/psychologists/{$p->slug}")->assertOk()->assertJsonPath('data.is_active', false)
            ->assertJsonMissingPath('data.price_individual');
    }

    public function test_admin_list_filters_and_counters(): void
    {
        $this->completePsychologist();
        $draft = $this->draftPsychologist();
        $inReview = $this->draftPsychologist();
        $inReview->forceFill(['qualification_status' => 'in_review', 'qualification_submitted_at' => now()])->save();

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/psychologists')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $inReview->id)
            ->assertJsonPath('counts.in_review', 1);
        $this->getJson('/api/v1/admin/psychologists?qualification_status=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft->id);
        $this->getJson('/api/v1/admin/psychologists?work_status=blocked')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/psychologists?activity_status=active_met')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/psychologists?price_category=standard')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/psychologists?q='.urlencode(mb_substr($draft->last_name, 0, 4)))->assertOk()
            ->assertJsonPath('data', fn ($rows) => in_array($draft->id, array_column($rows, 'id'), true));
        $this->getJson('/api/v1/admin/psychologists/not-a-uuid')->assertNotFound();
    }

    private function storedFile(Psychologist $p): StoredFile
    {
        return StoredFile::create([
            'owner_id' => $p->user_id, 'disk' => 'local', 'path' => 'qualification/test.pdf', 'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf', 'size' => 10, 'visibility' => 'private', 'purpose' => 'qualification',
        ]);
    }
}
