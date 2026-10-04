<?php

namespace App\Modules\Recommendations\Services;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Crm\Services\ClientRelationship;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Notifications\Notifier;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RECO: a psychologist attaches a recommendation to a held session within P-RECO-WINDOW days after it.
 * Sending notifies the client by a letter without content (BR-NOTIF-04): "new recommendation" and a link.
 */
class RecommendationService
{
    public function __construct(private ClientRelationship $relationship, private Notifier $notifier) {}

    /** Held sessions of the pair whose recommendation window is still open. */
    public function eligibleSessions(Psychologist $psychologist, string $clientId): Collection
    {
        $days = Settings::int('P-RECO-WINDOW');

        return TherapySession::query()
            ->where('psychologist_id', $psychologist->id)
            ->where('client_id', $clientId)
            ->where('status', TherapySession::HELD)
            ->where('ends_at', '>', now()->subDays($days))
            ->orderByDesc('starts_at')
            ->get(['id', 'starts_at', 'ends_at', 'format'])
            ->map(fn (TherapySession $s) => [
                'id' => $s->id,
                'starts_at' => $s->starts_at->toIso8601String(),
                'format' => $s->format,
                'window_until' => $s->ends_at->addDays($days)->toIso8601String(),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    public function create(Psychologist $psychologist, User $actor, array $data): Recommendation
    {
        $session = TherapySession::where('id', $data['session_id'])->where('psychologist_id', $psychologist->id)->first();
        if (! $session || $session->status !== TherapySession::HELD) {
            throw ValidationException::withMessages(['session_id' => 'Рекомендацию можно прикрепить только к проведённой сессии.']);
        }
        $windowUntil = CarbonImmutable::instance($session->ends_at)->addDays(Settings::int('P-RECO-WINDOW'));
        if ($windowUntil->isPast()) {
            throw ValidationException::withMessages(['session_id' => 'Окно для рекомендаций по этой сессии закрыто.']);
        }
        $this->ensureCanRecommend($psychologist, $session->client_id);
        $files = $this->files($actor, $data['file_ids'] ?? []);

        return DB::transaction(function () use ($psychologist, $actor, $data, $session, $windowUntil, $files) {
            $reco = new Recommendation([
                'psychologist_id' => $psychologist->id,
                'client_id' => $session->client_id,
                'session_id' => $session->id,
                'type' => $data['type'],
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'links' => $this->links($data['links'] ?? []),
                'due_date' => $data['due_date'] ?? null,
                'window_until' => $windowUntil,
            ]);
            $reco->forceFill(['status' => Recommendation::DRAFT])->save();
            $reco->recordInitialState($actor->id);
            $this->syncFiles($reco, $files);

            return $reco;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Recommendation $reco, User $actor, array $data): Recommendation
    {
        abort_unless($reco->status === Recommendation::DRAFT, 409, 'Изменить можно только черновик.');
        $files = array_key_exists('file_ids', $data) ? $this->files($actor, $data['file_ids'] ?? [], $reco) : null;

        DB::transaction(function () use ($reco, $data, $files) {
            $attributes = collect($data)->only(['type', 'title', 'body', 'due_date'])->all();
            if (array_key_exists('links', $data)) {
                $attributes['links'] = $this->links($data['links'] ?? []);
            }
            $reco->update($attributes);
            if ($files !== null) {
                $this->syncFiles($reco, $files);
            }
        });

        return $reco->refresh();
    }

    public function send(Recommendation $reco, User $actor): Recommendation
    {
        abort_unless($reco->status === Recommendation::DRAFT, 409, 'Рекомендация уже отправлена.');
        if ($reco->window_until->isPast()) {
            throw ValidationException::withMessages(['session_id' => 'Окно для рекомендаций по этой сессии закрыто.']);
        }
        $psychologist = Psychologist::withTrashed()->findOrFail($reco->psychologist_id);
        $this->ensureCanRecommend($psychologist, $reco->client_id);

        DB::transaction(function () use ($reco, $actor) {
            $reco->transitionTo(Recommendation::SENT, $actor->id, attributes: ['sent_at' => now()], context: [
                'client_id' => $reco->client_id, 'psychologist_id' => $reco->psychologist_id,
            ]);
            $client = User::find($reco->client_id);
            if ($client) {
                // No title or text in the letter: only the fact and a link to the cabinet.
                $this->notifier->send($client, 'reco.recommendation_sent', [], "/client/recommendations/{$reco->id}");
            }
        });

        return $reco;
    }

    public function revoke(Recommendation $reco, User $actor): Recommendation
    {
        abort_unless($reco->status === Recommendation::SENT, 409, 'Отозвать можно только рекомендацию, которую клиент ещё не открыл.');
        DB::transaction(function () use ($reco, $actor) {
            $reco->transitionTo(Recommendation::REVOKED, $actor->id, attributes: ['revoked_at' => now()]);
            // The unread centre item would lead nowhere: remove it (the letter has no content anyway).
            UserNotification::where('user_id', $reco->client_id)
                ->where('template_code', 'reco.recommendation_sent')
                ->where('link', "/client/recommendations/{$reco->id}")
                ->whereNull('read_at')
                ->delete();
        });

        return $reco;
    }

    /** CL-05: opening a sent recommendation marks it viewed. */
    public function markViewed(Recommendation $reco, User $client): Recommendation
    {
        if ($reco->status === Recommendation::SENT) {
            $reco->transitionTo(Recommendation::VIEWED, $client->id, attributes: ['viewed_at' => now()]);
        }

        return $reco;
    }

    public function markDone(Recommendation $reco, User $client): Recommendation
    {
        DB::transaction(function () use ($reco, $client) {
            $this->markViewed($reco, $client);
            if ($reco->status === Recommendation::VIEWED) {
                $reco->transitionTo(Recommendation::DONE, $client->id, attributes: ['done_at' => now()]);
            }
        });

        return $reco;
    }

    public function markNotDone(Recommendation $reco, User $client): Recommendation
    {
        if ($reco->status === Recommendation::DONE) {
            $reco->transitionTo(Recommendation::VIEWED, $client->id, attributes: ['done_at' => null]);
        }

        return $reco;
    }

    /** After the client changed psychologist the previous one cannot send anything new (DEC-28, DEC-48). */
    private function ensureCanRecommend(Psychologist $psychologist, string $clientId): void
    {
        $card = DB::table('client_cards')->where('psychologist_id', $psychologist->id)->where('client_id', $clientId)->first();
        if ($card && $card->changed_psychologist_at !== null && $this->relationship->accessUntil($psychologist->id, $clientId) !== null) {
            abort(409, 'Клиент сменил психолога: новые рекомендации отправить нельзя.');
        }
    }

    /**
     * Files uploaded by the author with purpose "recommendation" (or already attached to this recommendation).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function files(User $actor, array $ids, ?Recommendation $reco = null): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $attached = $reco ? DB::table('recommendation_files')->where('recommendation_id', $reco->id)->pluck('stored_file_id')->all() : [];
        $found = StoredFile::whereIn('id', $ids)
            ->where('purpose', 'recommendation')
            ->where(fn ($q) => $q->where('owner_id', $actor->id)->orWhereIn('id', $attached))
            ->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['file_ids' => 'Файл не найден: загрузите его заново.']);
        }

        return $ids;
    }

    /** @param  list<string>  $ids */
    private function syncFiles(Recommendation $reco, array $ids): void
    {
        $reco->files()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['sort' => $i]])->all());
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @return list<array{url: string, title: string|null, kb_material_id: string|null}>|null
     */
    private function links(array $links): ?array
    {
        $out = [];
        foreach ($links as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $out[] = [
                'url' => $url,
                'title' => isset($link['title']) && trim((string) $link['title']) !== '' ? trim((string) $link['title']) : null,
                'kb_material_id' => $link['kb_material_id'] ?? null,
            ];
        }

        return $out ?: null;
    }
}
