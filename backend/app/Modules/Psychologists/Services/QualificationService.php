<?php

namespace App\Modules\Psychologists\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Files\Services\FileStorage;
use App\Modules\Notifications\Notifier;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\QualificationDocument;
use App\Support\Events\Outbox;
use App\Support\StateMachine\StateTransition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRO-01 / ADM-03, ST-08, SEQ-12: qualification check — the basis of "Только дипломированные специалисты" (DEC-10).
 *  draft → in_review (submitted) → approved | rejected (comment is mandatory, BR-PSY-01); rejected → in_review,
 *  resubmission is unlimited (BR-PSY-03); approved → rejected when a document is found invalid: the profile is
 *  unpublished and BOOK cancels upcoming sessions on psy.qualification.rejected (BR-CANC-09).
 * Documents are frozen while the application is in review; after approval a new document is reviewed separately
 * and the status "approved" is kept (BR-PSY-05).
 */
class QualificationService
{
    public const MAX_DOCUMENTS = 20;

    public function __construct(
        private FileStorage $files,
        private Notifier $notifier,
        private ProfileCompleteness $completeness,
        private PublicationService $publication,
        private ActivityService $activity,
    ) {}

    /** @param  array{kind: string, title: string, institution?: string|null, specialty?: string|null, year?: int|null}  $meta */
    public function addDocument(Psychologist $p, UploadedFile $upload, array $meta, User $actor): QualificationDocument
    {
        if ($p->qualification_status === 'in_review') {
            throw ValidationException::withMessages(['file' => 'Пока заявка на модерации, документы менять нельзя. Дождитесь решения администратора.']);
        }
        if ($p->documents()->count() >= self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['file' => 'Можно загрузить не больше '.self::MAX_DOCUMENTS.' документов.']);
        }
        $file = $this->files->store($upload, 'qualification', $actor);

        return DB::transaction(function () use ($p, $file, $meta, $actor) {
            $document = QualificationDocument::create([
                'psychologist_id' => $p->id,
                'file_id' => $file->id,
                'kind' => $meta['kind'],
                'title' => $meta['title'],
                'institution' => $meta['institution'] ?? null,
                'specialty' => $meta['specialty'] ?? null,
                'year' => $meta['year'] ?? null,
                'status' => 'pending',
            ]);

            if ($p->qualification_status === 'approved') {
                // Approved → approved: the new document is checked separately, the status is kept (ST-08).
                Outbox::record('psy.qualification.document_added', $p, ['document_id' => $document->id, 'kind' => $document->kind], $actor->id);
                foreach (AdminRecipients::withPermission('admin.psychologists.verify') as $admin) {
                    $this->notifier->send($admin, 'psy.qualification_new_document', ['psychologist' => $p->fullName(), 'title' => $document->title], "/admin/psychologists/{$p->id}");
                }
            }

            return $document;
        });
    }

    public function deleteDocument(Psychologist $p, QualificationDocument $document): void
    {
        $allowed = match ($p->qualification_status) {
            'draft', 'rejected' => true,
            'approved' => $document->status !== 'approved',
            default => false,
        };
        if (! $allowed) {
            throw ValidationException::withMessages(['document' => $p->qualification_status === 'in_review'
                ? 'Пока заявка на модерации, документы менять нельзя.'
                : 'Подтверждённый документ удалить нельзя: на нём основана проверка квалификации.']);
        }
        DB::transaction(function () use ($document) {
            $fileId = $document->file_id;
            $document->delete();
            StoredFile::whereKey($fileId)->first()?->delete();
        });
    }

    public function submit(Psychologist $p, User $actor): void
    {
        if (! in_array($p->qualification_status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['qualification' => $p->qualification_status === 'in_review'
                ? 'Заявка уже на модерации.'
                : 'Квалификация уже подтверждена.']);
        }
        $missing = $this->completeness->missingForSubmission($p);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'qualification' => array_map(fn ($m) => $m['label'].': '.$m['hint'], $missing),
            ]);
        }

        DB::transaction(function () use ($p, $actor) {
            $resubmission = $p->qualification_status === 'rejected';
            $p->transitionTo('in_review', $actor->id, $resubmission ? 'resubmitted' : 'submitted', [
                'qualification_submitted_at' => now(),
            ], ['resubmission' => $resubmission], field: 'qualification_status', event: 'psy.qualification.submitted');

            $this->notifier->send($p->user, 'psy.qualification_submitted', [], '/pro/qualification');
            foreach (AdminRecipients::withPermission('admin.psychologists.verify') as $admin) {
                $this->notifier->send($admin, 'psy.qualification_new_submission', ['psychologist' => $p->fullName()], "/admin/psychologists/{$p->id}");
            }
        });
    }

    public function approve(Psychologist $p, User $admin, ?string $comment = null): void
    {
        if ($p->qualification_status !== 'in_review') {
            throw ValidationException::withMessages(['qualification' => 'Подтвердить можно только заявку на модерации.']);
        }
        $educationApprovable = $p->documents()
            ->whereIn('kind', Psychologist::EDUCATION_DOCUMENT_KINDS)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        if (! $educationApprovable) {
            throw ValidationException::withMessages(['qualification' => 'Нет ни одного документа о психологическом образовании, который можно подтвердить.']);
        }

        DB::transaction(function () use ($p, $admin, $comment) {
            $p->documents()->where('status', 'pending')->update([
                'status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(),
            ]);
            $p->transitionTo('approved', $admin->id, $comment, [
                'qualification_comment' => $comment,
                'qualified_at' => $p->qualified_at ?? now(),
            ], field: 'qualification_status', event: 'psy.qualification.approved');

            // ST-09: "Активен, льготный период" — the supervision requirement starts with the first full month (DEC-37).
            $this->activity->onQualified($p, $admin->id);
            $this->publication->sync($p->refresh(), $admin->id);

            Audit::log('ADM-03', 'psychologist.qualification_approved', $p, null, $comment, $admin->id);
            $this->notifier->send($p->user, 'psy.qualification_approved', ['comment' => $comment ?? ''], '/pro/profile');
        });
    }

    /** @param  list<string>  $documentIds  documents found invalid */
    public function reject(Psychologist $p, User $admin, string $comment, array $documentIds = []): void
    {
        $from = $p->qualification_status;
        if (! in_array($from, ['in_review', 'approved'], true)) {
            throw ValidationException::withMessages(['qualification' => 'Отклонить можно заявку на модерации или подтверждённую квалификацию.']);
        }
        $documents = $p->documents()->whereIn('id', $documentIds)->get();
        if (count($documentIds) !== $documents->count()) {
            throw ValidationException::withMessages(['document_ids' => 'Документ не найден у этого психолога.']);
        }

        DB::transaction(function () use ($p, $admin, $comment, $documents, $from) {
            foreach ($documents as $document) {
                $document->forceFill(['status' => 'rejected', 'comment' => $comment, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            }
            $p->transitionTo('rejected', $admin->id, $comment, ['qualification_comment' => $comment], [
                'previous' => $from, 'revoked' => $from === 'approved', 'document_ids' => $documents->pluck('id')->all(),
            ], field: 'qualification_status', event: 'psy.qualification.rejected');
            $this->publication->sync($p->refresh(), $admin->id);

            Audit::log('ADM-03', $from === 'approved' ? 'psychologist.qualification_revoked' : 'psychologist.qualification_rejected', $p, [
                'documents' => $documents->pluck('title')->all(),
            ], $comment, $admin->id);
            $this->notifier->send($p->user, 'psy.qualification_rejected', ['comment' => $comment], '/pro/qualification');
        });
    }

    /** Per-document decision during review, or a separate check of a new document after approval (ST-08). */
    public function reviewDocument(Psychologist $p, QualificationDocument $document, string $status, ?string $comment, User $admin): void
    {
        if (! in_array($p->qualification_status, ['in_review', 'approved'], true)) {
            throw ValidationException::withMessages(['document' => 'Документы проверяются, когда заявка на модерации или квалификация подтверждена.']);
        }
        if ($status === 'rejected' && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'Укажите причину отклонения документа.']);
        }

        DB::transaction(function () use ($p, $document, $status, $comment, $admin) {
            $document->forceFill(['status' => $status, 'comment' => $comment, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            Outbox::record('psy.qualification.document_reviewed', $p, ['document_id' => $document->id, 'status' => $status], $admin->id);
            Audit::log('ADM-03', 'psychologist.document_'.$status, $p, ['document' => $document->title], $comment, $admin->id);
            if ($p->qualification_status === 'approved') {
                $this->notifier->send($p->user, $status === 'approved' ? 'psy.document_approved' : 'psy.document_rejected', [
                    'title' => $document->title, 'comment' => $comment ?? '',
                ], '/pro/qualification');
            }
        });
    }

    /** Applications and decisions, oldest first (BR-PSY-03: visible to the psychologist and the administrator). */
    public function history(Psychologist $p, bool $withActors = false): array
    {
        return StateTransition::query()
            ->where('model_type', $p->getMorphClass())
            ->where('model_id', $p->id)
            ->where('field', 'qualification_status')
            ->with('actor')
            ->orderBy('created_at')
            ->get()
            ->map(fn (StateTransition $t) => [
                'from' => $t->from,
                'to' => $t->to,
                'event' => $t->event,
                'comment' => in_array($t->to, ['approved', 'rejected'], true) ? $t->reason : null,
                'at' => $t->created_at?->toIso8601ZuluString(),
                ...($withActors ? ['actor' => $t->actor ? trim($t->actor->name.' '.($t->actor->last_name ?? '')) : null] : []),
            ])->all();
    }

    public function documentRow(QualificationDocument $d, bool $withUrl): array
    {
        return [
            'id' => $d->id,
            'kind' => $d->kind,
            'title' => $d->title,
            'institution' => $d->institution,
            'specialty' => $d->specialty,
            'year' => $d->year,
            'status' => $d->status,
            'comment' => $d->comment,
            'reviewed_at' => $d->reviewed_at?->toIso8601ZuluString(),
            'created_at' => $d->created_at?->toIso8601ZuluString(),
            'file' => $d->file ? [
                'name' => $d->file->original_name, 'mime_type' => $d->file->mime_type, 'size' => $d->file->size,
                ...($withUrl ? ['url' => $d->file->url(30)] : []),
            ] : null,
        ];
    }
}
