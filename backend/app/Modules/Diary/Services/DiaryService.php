<?php

namespace App\Modules\Diary\Services;

use App\Models\User;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Diary\Models\DiaryPromptState;
use App\Modules\Diary\Models\EmotionTag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * DIARY (DEC-41): check-in once a day at login, history and dynamics. Days are counted in the client's time zone.
 * Nothing from here is logged, audited or sent anywhere except the client and their psychologist (aggregates only).
 */
class DiaryService
{
    /** Period presets in days. */
    public const PERIODS = ['week' => 7, 'month' => 30, 'quarter' => 90, 'year' => 365];

    public const GROUPS = ['day', 'week'];

    public function timezone(User $client): string
    {
        return $client->timezone ?: (string) config('platform.timezone', 'Europe/Moscow');
    }

    public function today(User $client): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($client))->startOfDay();
    }

    /** CL-01: offer the check-in only if there is no entry today and it was not skipped today. */
    public function shouldPrompt(User $client): bool
    {
        $today = $this->today($client)->toDateString();
        if (DiaryEntry::where('client_id', $client->id)->where('local_date', $today)->exists()) {
            return false;
        }
        $dismissed = DiaryPromptState::whereKey($client->id)->value('dismissed_on');

        return $dismissed === null || substr((string) $dismissed, 0, 10) !== $today;
    }

    public function skip(User $client): void
    {
        DiaryPromptState::updateOrCreate(['client_id' => $client->id], ['dismissed_on' => $this->today($client)->toDateString()]);
    }

    /** @param  list<string>  $tagIds */
    public function record(User $client, int $mood, array $tagIds = [], ?string $note = null): DiaryEntry
    {
        $note = $note !== null ? trim($note) : null;

        return DiaryEntry::create([
            'client_id' => $client->id,
            'mood' => $mood,
            'tag_ids' => array_values(array_unique($tagIds)) ?: null,
            'note' => $note === '' ? null : $note,
            'recorded_at' => now(),
            'local_date' => $this->today($client)->toDateString(),
        ]);
    }

    /** @return array<string, array{id: string, code: string, title: string}> all tags, inactive ones too (history) */
    public function tagsById(): array
    {
        return EmotionTag::query()->get()->mapWithKeys(fn (EmotionTag $t) => [$t->id => $t->toApi()])->all();
    }

    /**
     * Resolve the requested period in the client's calendar.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    public function period(User $client, ?string $preset, ?string $from = null, ?string $to = null, ?string $group = null): array
    {
        $tz = $this->timezone($client);
        $today = $this->today($client);
        if ($from !== null) {
            $start = CarbonImmutable::parse($from, $tz)->startOfDay();
            $end = $to !== null ? CarbonImmutable::parse($to, $tz)->startOfDay() : $today;
        } else {
            $days = self::PERIODS[$preset ?? 'month'] ?? self::PERIODS['month'];
            $end = $today;
            $start = $today->subDays($days - 1);
        }
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }
        $group = in_array($group, self::GROUPS, true) ? $group : ($start->diffInDays($end) > 62 ? 'week' : 'day');

        return [$start, $end, $group];
    }

    /**
     * Dynamics: average mood per day or week, most frequent tags and a summary. Notes are never included.
     * $until limits the data to entries recorded before that moment (DM-08, previous psychologist).
     *
     * @return array<string, mixed>
     */
    public function dynamics(string $clientId, CarbonImmutable $from, CarbonImmutable $to, string $group, ?CarbonImmutable $until = null): array
    {
        $base = fn (): Builder => DiaryEntry::query()
            ->where('client_id', $clientId)
            ->whereBetween('local_date', [$from->toDateString(), $to->toDateString()])
            ->when($until !== null, fn (Builder $q) => $q->where('recorded_at', '<', $until));

        $bucket = $group === 'week' ? "(date_trunc('week', local_date))::date" : 'local_date';
        $points = $base()->toBase()
            ->selectRaw("{$bucket} as bucket, round(avg(mood)::numeric, 2) as avg_mood, min(mood) as min_mood, max(mood) as max_mood, count(*) as entries")
            ->groupBy(DB::raw($bucket))
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'date' => substr((string) $row->bucket, 0, 10),
                'avg_mood' => (float) $row->avg_mood,
                'min_mood' => (int) $row->min_mood,
                'max_mood' => (int) $row->max_mood,
                'entries' => (int) $row->entries,
            ])->values()->all();

        $tags = $this->tagsById();
        $tagCounts = [];
        foreach ($base()->pluck('tag_ids') as $ids) {
            foreach ((array) $ids as $id) {
                if (isset($tags[$id])) {
                    $tagCounts[$id] = ($tagCounts[$id] ?? 0) + 1;
                }
            }
        }
        arsort($tagCounts);
        $topTags = collect($tagCounts)->take(10)->map(fn ($count, $id) => [...$tags[$id], 'count' => $count])->values()->all();

        $summary = $base()->toBase()->selectRaw('count(*) as entries, round(avg(mood)::numeric, 2) as avg_mood, count(distinct local_date) as days')->first();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group' => $group,
            'points' => $points,
            'tags' => $topTags,
            'summary' => [
                'entries' => (int) ($summary->entries ?? 0),
                'avg_mood' => $summary?->avg_mood !== null ? (float) $summary->avg_mood : null,
                'days_with_entries' => (int) ($summary->days ?? 0),
            ],
        ];
    }

    /** Account deletion (SEQ-22): the diary is destroyed. Returns the number of entries. */
    public function destroyForUser(string $userId): int
    {
        DiaryPromptState::whereKey($userId)->delete();

        return DiaryEntry::where('client_id', $userId)->delete();
    }
}
