<?php

namespace App\Modules\ClientHome\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Crm\Services\ClientRelationship;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Diary\Services\DiaryService;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CL-02: data for the client's cabinet home in one request. Every block is computed independently and degrades
 * to null when its data is unavailable, so the page works while other modules are still being built.
 */
class ClientHomeController extends Controller
{
    public function __construct(private DiaryService $diary, private ClientRelationship $relationship) {}

    public function show(Request $request)
    {
        $client = $request->user();
        $openBefore = Settings::int('P-ROOM-OPEN');
        $closeAfter = Settings::int('P-ROOM-CLOSE');

        return response()->json(['data' => [
            'room_window' => ['open_before_min' => $openBefore, 'close_after_min' => $closeAfter],
            'next_session' => $this->block('next_session', fn () => $this->nextSession($client, $openBefore, $closeAfter)),
            'diary' => $this->block('diary', fn () => $this->diaryBlock($client)),
            'recommendations' => $this->block('recommendations', fn () => $this->recommendations($client)),
            'psychologists' => $this->block('psychologists', fn () => $this->psychologists($client)) ?? [],
            'timezone' => $client->timezone,
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    /** @return array<string, mixed>|null */
    private function nextSession(User $client, int $openBefore, int $closeAfter): ?array
    {
        $session = TherapySession::query()
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhere('partner_user_id', $client->id))
            ->whereIn('status', TherapySession::ACTIVE_STATUSES)
            ->where('ends_at', '>', now()->subMinutes($closeAfter))
            ->orderBy('starts_at')
            ->with('psychologist.photo')
            ->first();
        if (! $session) {
            return null;
        }

        return [
            'id' => $session->id,
            'starts_at' => $session->starts_at->toIso8601String(),
            'ends_at' => $session->ends_at->toIso8601String(),
            'duration_min' => $session->duration_min,
            'format' => $session->format,
            'status' => $session->status,
            'is_paid' => $session->paid_at !== null,
            'psychologist' => $this->psychologistCard($session->psychologist),
            'room' => [
                'url' => "/room/session/{$session->id}",
                'opens_at' => $session->starts_at->subMinutes($openBefore)->toIso8601String(),
                'closes_at' => $session->ends_at->addMinutes($closeAfter)->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function diaryBlock(User $client): array
    {
        $today = $this->diary->today($client);
        $last = DiaryEntry::where('client_id', $client->id)->orderByDesc('recorded_at')->first(['id', 'mood', 'local_date', 'recorded_at']);
        $recent = $this->diary->dynamics($client->id, $today->subDays(13), $today, 'day');

        return [
            'show_prompt' => $this->diary->shouldPrompt($client),
            'today' => $today->toDateString(),
            'today_mood' => $last && $last->local_date->toDateString() === $today->toDateString() ? $last->mood : null,
            'recent' => ['from' => $recent['from'], 'to' => $recent['to'], 'points' => $recent['points'], 'avg_mood' => $recent['summary']['avg_mood']],
        ];
    }

    /** @return array<string, mixed> */
    private function recommendations(User $client): array
    {
        $items = Recommendation::where('client_id', $client->id)
            ->whereIn('status', [Recommendation::SENT, Recommendation::VIEWED])
            ->with('psychologist:id,slug,first_name,last_name')
            ->orderByRaw('case when status = ? then 0 else 1 end', [Recommendation::SENT])
            ->orderByDesc('sent_at')
            ->limit(3)
            ->get();

        return [
            'unread_count' => Recommendation::where('client_id', $client->id)->where('status', Recommendation::SENT)->count(),
            'active_count' => Recommendation::where('client_id', $client->id)->whereIn('status', [Recommendation::SENT, Recommendation::VIEWED])->count(),
            'items' => $items->map(fn (Recommendation $r) => [
                'id' => $r->id, 'type' => $r->type, 'title' => $r->title, 'status' => $r->status,
                'sent_at' => $r->sent_at?->toIso8601String(), 'due_date' => $r->due_date?->toDateString(),
                'psychologist_name' => $r->psychologist?->fullName(),
            ])->values()->all(),
        ];
    }

    /**
     * "My psychologist" read directly from therapy_sessions until BOOK exposes /booking/my-psychologists:
     * psychologists the client is booked with or had held sessions with, except those the client changed away from.
     *
     * @return list<array<string, mixed>>
     */
    private function psychologists(User $client): array
    {
        $rows = DB::table('therapy_sessions')
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhere('partner_user_id', $client->id))
            ->whereIn('status', ClientRelationship::DIARY_STATUSES)
            ->groupBy('psychologist_id')
            ->selectRaw('psychologist_id, count(*) filter (where status in (?, ?, ?) and ends_at > ?) as upcoming, max(starts_at) filter (where status = ?) as last_held, max(starts_at) as last_any',
                [...TherapySession::ACTIVE_STATUSES, now(), TherapySession::HELD])
            ->orderByDesc('last_any')
            ->get();

        $psychologists = Psychologist::withTrashed()->with('photo')->whereIn('id', $rows->pluck('psychologist_id'))->get()->keyBy('id');
        $out = [];
        foreach ($rows as $row) {
            $p = $psychologists->get($row->psychologist_id);
            if (! $p) {
                continue;
            }
            $changedAway = DB::table('client_cards')->where('psychologist_id', $p->id)->where('client_id', $client->id)->whereNotNull('changed_psychologist_at')->exists()
                && $this->relationship->accessUntil($p->id, $client->id) !== null;
            if ($changedAway) {
                continue;
            }
            $out[] = [
                'psychologist_id' => $p->id,
                ...$this->psychologistCard($p),
                'upcoming_sessions' => (int) $row->upcoming,
                'last_session_at' => $row->last_held ? CarbonImmutable::parse($row->last_held, 'UTC')->toIso8601String() : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function psychologistCard(?Psychologist $p): ?array
    {
        if (! $p) {
            return null;
        }

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->fullName(),
            'headline' => $p->headline,
            'photo_url' => $p->photo?->url(),
            'is_bookable' => $p->isBookable(),
            'profile_url' => "/psychologists/{$p->slug}",
        ];
    }

    private function block(string $name, callable $compute): mixed
    {
        try {
            return $compute();
        } catch (Throwable $e) {
            Log::warning("Client home block {$name} is unavailable: ".$e::class);

            return null;
        }
    }
}
