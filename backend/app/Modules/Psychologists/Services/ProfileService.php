<?php

namespace App\Modules\Psychologists\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Catalog\Services\SearchIndex;
use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Dictionaries\Models\Specialization;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Files\Services\FileStorage;
use App\Modules\Notifications\Notifier;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Events\Outbox;
use App\Support\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PRO-02 profile. Public fields of an APPROVED psychologist are not applied at once: they go to pending_changes and
 * wait for moderation; the site shows the published version until then (BR-PSY-05). Before approval the profile is
 * not public, so edits apply directly. Prices and formats are set by the psychologist (DEC-19) and apply at once;
 * the price category follows the individual price (DEC-55). Bookings keep their own fixed price, so a price change
 * never affects existing sessions. The video card is moderated separately (DEC-44).
 */
class ProfileService
{
    /** Public fields moderated for approved psychologists. */
    public const MODERATED = [
        'first_name', 'last_name', 'gender', 'birth_year', 'headline', 'about', 'experience_years', 'education',
        'approaches', 'specializations', 'requests', 'photo_file_id',
    ];

    /** Operational fields applied at once. */
    public const DIRECT = ['works_individual', 'works_pair', 'price_individual', 'price_pair'];

    private const SCALARS = ['first_name', 'last_name', 'gender', 'birth_year', 'headline', 'about', 'experience_years', 'photo_file_id'];

    public function __construct(
        private Notifier $notifier,
        private PublicationService $publication,
        private SearchIndex $search,
        private FileStorage $files,
    ) {}

    public function requiresModeration(Psychologist $p): bool
    {
        return $p->qualification_status === 'approved';
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     * @return array{applied: list<string>, pending: list<string>}
     */
    public function update(Psychologist $p, array $data, User $actor): array
    {
        $direct = array_intersect_key($data, array_flip(self::DIRECT));
        $moderated = $this->normalizeAll(array_intersect_key($data, array_flip(self::MODERATED)));

        return DB::transaction(function () use ($p, $direct, $moderated, $actor) {
            $applied = [];
            if ($direct !== []) {
                $applied = $this->applyDirect($p, $direct, $actor);
            }

            $pending = [];
            if ($moderated !== []) {
                if ($this->requiresModeration($p)) {
                    $pending = $this->stagePending($p, $moderated, $actor);
                } else {
                    $this->applyModerated($p, $moderated);
                    $applied = [...$applied, ...array_keys($moderated)];
                }
            }

            $this->search->refresh($p);
            $this->publication->sync($p->refresh(), $actor->id);

            return ['applied' => array_values(array_unique($applied)), 'pending' => $pending];
        });
    }

    public function setPhoto(Psychologist $p, UploadedFile $upload, User $actor): array
    {
        $file = $this->files->store($upload, 'avatar', $actor);

        return $this->update($p, ['photo_file_id' => $file->id], $actor);
    }

    public function removePhoto(Psychologist $p, User $actor): array
    {
        return $this->update($p, ['photo_file_id' => null], $actor);
    }

    // ---- Pending changes (BR-PSY-05) ------------------------------------------------------------------

    public function approvePending(Psychologist $p, User $admin, ?string $comment = null): void
    {
        $pending = (array) ($p->pending_changes ?? []);
        if ($pending === []) {
            throw ValidationException::withMessages(['pending' => 'Нет изменений на модерации.']);
        }
        $diff = $this->pendingDiff($p);

        DB::transaction(function () use ($p, $pending, $admin, $comment, $diff) {
            $this->applyModerated($p, $pending);
            $p->forceFill([
                'pending_changes' => null, 'pending_submitted_at' => null,
                'pending_review_comment' => $comment, 'pending_reviewed_at' => now(),
            ])->save();
            $this->search->refresh($p);
            $this->publication->sync($p->refresh(), $admin->id);

            Outbox::record('psy.profile.changes_approved', $p, ['fields' => array_keys($pending)], $admin->id);
            Audit::log('ADM-03', 'psychologist.changes_approved', $p, ['fields' => array_column($diff, 'field')], $comment, $admin->id);
            $this->notifier->send($p->user, 'psy.profile_changes_approved', ['comment' => $comment ?? ''], '/pro/profile');
        });
    }

    public function rejectPending(Psychologist $p, User $admin, string $comment): void
    {
        $pending = (array) ($p->pending_changes ?? []);
        if ($pending === []) {
            throw ValidationException::withMessages(['pending' => 'Нет изменений на модерации.']);
        }

        DB::transaction(function () use ($p, $pending, $admin, $comment) {
            if (! empty($pending['photo_file_id']) && $pending['photo_file_id'] !== $p->photo_file_id) {
                StoredFile::whereKey($pending['photo_file_id'])->first()?->delete();
            }
            $p->forceFill([
                'pending_changes' => null, 'pending_submitted_at' => null,
                'pending_review_comment' => $comment, 'pending_reviewed_at' => now(),
            ])->save();

            Outbox::record('psy.profile.changes_rejected', $p, ['fields' => array_keys($pending)], $admin->id);
            Audit::log('ADM-03', 'psychologist.changes_rejected', $p, ['fields' => array_keys($pending)], $comment, $admin->id);
            $this->notifier->send($p->user, 'psy.profile_changes_rejected', ['comment' => $comment], '/pro/profile');
        });
    }

    /**
     * Human-readable diff "on the site now → on moderation" for ADM-03.
     *
     * @return list<array{field: string, label: string, before: mixed, after: mixed}>
     */
    public function pendingDiff(Psychologist $p): array
    {
        $pending = (array) ($p->pending_changes ?? []);
        $current = $this->currentValues($p);
        $out = [];
        foreach (self::MODERATED as $field) {
            if (! array_key_exists($field, $pending)) {
                continue;
            }
            $out[] = [
                'field' => $field,
                'label' => ProfileCompleteness::LABELS[$field],
                'before' => $this->present($field, $current[$field] ?? null),
                'after' => $this->present($field, $pending[$field]),
            ];
        }

        return $out;
    }

    /**
     * Values of the moderated fields as stored in the profile (normalized like pending_changes).
     *
     * @return array<string, mixed>
     */
    public function currentValues(Psychologist $p): array
    {
        $p->loadMissing(['approaches', 'specializations', 'requests']);
        $values = [];
        foreach (self::SCALARS as $field) {
            $values[$field] = $p->getAttribute($field);
        }
        $values['education'] = $this->normalize('education', $p->education ?? []);
        $values['approaches'] = $this->normalize('approaches', $p->approaches->map(fn ($a) => ['id' => $a->id, 'explanation' => $a->pivot->explanation])->all());
        $values['specializations'] = $this->normalize('specializations', $p->specializations->pluck('id')->all());
        $values['requests'] = $this->normalize('requests', $p->requests->pluck('id')->all());

        return $values;
    }

    /** Published values with pending ones on top: what the psychologist sees in the form. */
    public function effectiveValues(Psychologist $p): array
    {
        return [...$this->currentValues($p), ...(array) ($p->pending_changes ?? [])];
    }

    // ---- Video card (DEC-44) ------------------------------------------------------------------------

    public function uploadVideo(Psychologist $p, UploadedFile $upload, ?int $durationSec, User $actor): void
    {
        $limits = (array) Settings::get('P-VIDEO-CARD-LIMITS');
        $maxMb = (int) ($limits['megabytes'] ?? 100);
        $maxSec = (int) ($limits['seconds'] ?? 90);
        if ($upload->getSize() > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => "Видеовизитка должна быть не больше {$maxMb} МБ."]);
        }
        if ($durationSec !== null && $durationSec > $maxSec) {
            throw ValidationException::withMessages(['duration' => "Видеовизитка должна длиться не дольше {$maxSec} секунд."]);
        }
        $file = $this->files->store($upload, 'video_card', $actor);

        DB::transaction(function () use ($p, $file, $durationSec, $actor) {
            $previous = $p->video_file_id;
            $p->transitionTo('pending', $actor->id, 'video uploaded', [
                'video_file_id' => $file->id, 'video_duration_sec' => $durationSec, 'video_comment' => null,
                'video_submitted_at' => now(), 'video_reviewed_at' => null,
            ], field: 'video_status', event: 'psy.video.submitted');
            if ($previous && $previous !== $p->video_approved_file_id) {
                StoredFile::whereKey($previous)->first()?->delete();
            }
            $this->notifyModerators($p, 'видеовизитка');
        });
    }

    public function removeVideo(Psychologist $p, User $actor): void
    {
        if ($p->video_status === 'none') {
            return;
        }
        DB::transaction(function () use ($p, $actor) {
            $files = array_filter([$p->video_file_id, $p->video_approved_file_id]);
            $p->transitionTo('none', $actor->id, 'video removed', [
                'video_file_id' => null, 'video_approved_file_id' => null, 'video_duration_sec' => null,
                'video_comment' => null, 'video_submitted_at' => null, 'video_reviewed_at' => null,
            ], field: 'video_status', event: 'psy.video.removed');
            StoredFile::whereIn('id', $files)->get()->each->delete();
        });
    }

    public function approveVideo(Psychologist $p, User $admin, ?string $comment = null): void
    {
        $this->assertVideoPending($p);
        DB::transaction(function () use ($p, $admin, $comment) {
            $previous = $p->video_approved_file_id;
            $p->transitionTo('approved', $admin->id, $comment, [
                'video_approved_file_id' => $p->video_file_id, 'video_comment' => $comment, 'video_reviewed_at' => now(),
            ], field: 'video_status', event: 'psy.video.approved');
            if ($previous && $previous !== $p->video_file_id) {
                StoredFile::whereKey($previous)->first()?->delete();
            }
            Audit::log('ADM-03', 'psychologist.video_approved', $p, null, $comment, $admin->id);
            $this->notifier->send($p->user, 'psy.video_approved', [], '/pro/profile');
        });
    }

    public function rejectVideo(Psychologist $p, User $admin, string $comment): void
    {
        $this->assertVideoPending($p);
        DB::transaction(function () use ($p, $admin, $comment) {
            $p->transitionTo('rejected', $admin->id, $comment, [
                'video_comment' => $comment, 'video_reviewed_at' => now(),
            ], field: 'video_status', event: 'psy.video.rejected');
            Audit::log('ADM-03', 'psychologist.video_rejected', $p, null, $comment, $admin->id);
            $this->notifier->send($p->user, 'psy.video_rejected', ['comment' => $comment], '/pro/profile');
        });
    }

    // ---- Internals ------------------------------------------------------------------------------------

    /** @return list<string> */
    private function applyDirect(Psychologist $p, array $direct, User $actor): array
    {
        $p->fill($direct);
        $priceChanged = $p->isDirty('price_individual') || $p->isDirty('price_pair');
        if ($priceChanged) {
            $p->price_category_id = PriceCategory::forPrice($p->price_individual)?->id;
        }
        $changed = array_keys($p->getDirty());
        $p->save();

        if ($priceChanged) {
            DB::table('psychologist_price_history')->insert([
                'id' => (string) Str::uuid(),
                'psychologist_id' => $p->id,
                'price_individual' => $p->price_individual,
                'price_pair' => $p->price_pair,
                'price_category_id' => $p->price_category_id,
                'created_at' => now(),
            ]);
            Outbox::record('psy.profile.price_changed', $p, [
                'price_individual' => $p->price_individual, 'price_pair' => $p->price_pair, 'price_category_id' => $p->price_category_id,
            ], $actor->id);
        }

        return array_values(array_diff($changed, ['price_category_id', 'updated_at']));
    }

    /** @return list<string> fields now waiting for moderation */
    private function stagePending(Psychologist $p, array $moderated, User $actor): array
    {
        $current = $this->currentValues($p);
        $before = (array) ($p->pending_changes ?? []);
        $pending = $before;
        foreach ($moderated as $field => $value) {
            if ($this->same($value, $current[$field] ?? null)) {
                unset($pending[$field]);
            } else {
                $pending[$field] = $value;
            }
        }
        $pending = $this->ordered($pending);
        $before = $this->ordered($before);
        $changed = ! $this->same($pending, $before);

        if ($changed) {
            if (array_key_exists('photo_file_id', $before) && ($pending['photo_file_id'] ?? null) !== $before['photo_file_id'] && $before['photo_file_id']) {
                StoredFile::whereKey($before['photo_file_id'])->first()?->delete();
            }
            $p->forceFill([
                'pending_changes' => $pending ?: null,
                'pending_submitted_at' => $pending ? now() : null,
                'pending_review_comment' => null,
                'pending_reviewed_at' => null,
            ])->save();
            if ($pending) {
                Outbox::record('psy.profile.changes_submitted', $p, ['fields' => array_keys($pending)], $actor->id);
                if ($before === []) {
                    $this->notifyModerators($p, 'изменения профиля');
                }
            }
        }

        return array_keys($pending);
    }

    private function applyModerated(Psychologist $p, array $values): void
    {
        $replacedPhoto = $p->photo_file_id;
        $scalars = array_intersect_key($values, array_flip(self::SCALARS));
        if ($scalars !== []) {
            $p->forceFill($scalars);
        }
        if (array_key_exists('education', $values)) {
            $p->education = $values['education'];
        }
        $p->save();
        if ($replacedPhoto && $replacedPhoto !== $p->photo_file_id) {
            StoredFile::whereKey($replacedPhoto)->first()?->delete();
        }

        if (array_key_exists('approaches', $values)) {
            $valid = Approach::whereIn('id', array_column($values['approaches'], 'id'))->pluck('id')->all();
            $p->approaches()->sync(collect($values['approaches'])->filter(fn ($a) => in_array($a['id'], $valid, true))
                ->mapWithKeys(fn ($a) => [$a['id'] => ['explanation' => $a['explanation']]])->all());
        }
        if (array_key_exists('specializations', $values)) {
            $p->specializations()->sync(Specialization::whereIn('id', $values['specializations'])->pluck('id')->all());
        }
        if (array_key_exists('requests', $values)) {
            $p->requests()->sync(ClientRequest::whereIn('id', $values['requests'])->pluck('id')->all());
        }
        $p->unsetRelation('approaches')->unsetRelation('specializations')->unsetRelation('requests');
    }

    /** Pending fields in the canonical order of MODERATED (jsonb does not keep key order). */
    public function ordered(array $pending): array
    {
        return array_replace(array_intersect_key(array_flip(self::MODERATED), $pending), $pending);
    }

    private function normalizeAll(array $values): array
    {
        $out = [];
        foreach ($values as $field => $value) {
            $out[$field] = $this->normalize($field, $value);
        }

        return $out;
    }

    private function normalize(string $field, mixed $value): mixed
    {
        return match ($field) {
            'education' => array_values(array_map(fn ($e) => [
                'institution' => trim((string) ($e['institution'] ?? '')),
                'specialty' => ($s = trim((string) ($e['specialty'] ?? ''))) === '' ? null : $s,
                'year' => isset($e['year']) && $e['year'] !== '' && $e['year'] !== null ? (int) $e['year'] : null,
            ], array_filter((array) $value, fn ($e) => is_array($e) && trim((string) ($e['institution'] ?? '')) !== ''))),
            'approaches' => collect((array) $value)->map(fn ($a) => [
                'id' => (string) $a['id'],
                'explanation' => ($x = trim((string) ($a['explanation'] ?? ''))) === '' ? null : $x,
            ])->unique('id')->sortBy('id')->values()->all(),
            'specializations', 'requests' => collect((array) $value)->map(fn ($id) => (string) $id)->unique()->sort()->values()->all(),
            'headline', 'about', 'first_name', 'last_name' => is_string($value) && trim($value) !== '' ? trim($value) : null,
            'birth_year', 'experience_years' => $value === null || $value === '' ? null : (int) $value,
            default => $value,
        };
    }

    private function same(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    private function present(string $field, mixed $value): mixed
    {
        return match ($field) {
            'approaches' => collect((array) $value)->map(fn ($a) => [
                'title' => Approach::find($a['id'])?->title ?? '—', 'explanation' => $a['explanation'] ?? null,
            ])->all(),
            'specializations' => Specialization::whereIn('id', (array) $value)->orderBy('sort')->pluck('title')->all(),
            'requests' => ClientRequest::whereIn('id', (array) $value)->orderBy('carousel_sort')->pluck('title')->all(),
            'photo_file_id' => $value ? StoredFile::withTrashed()->find($value)?->url() : null,
            'gender' => match ($value) {
                'female' => 'Женский', 'male' => 'Мужской', default => null,
            },
            default => $value,
        };
    }

    private function assertVideoPending(Psychologist $p): void
    {
        if ($p->video_status !== 'pending') {
            throw ValidationException::withMessages(['video' => 'Нет видеовизитки на модерации.']);
        }
    }

    private function notifyModerators(Psychologist $p, string $what): void
    {
        foreach (AdminRecipients::withPermission('admin.psychologists.moderate_profile') as $admin) {
            $this->notifier->send($admin, 'psy.moderation_requested', ['psychologist' => $p->fullName(), 'what' => $what], "/admin/psychologists/{$p->id}");
        }
    }
}
