<?php

namespace App\Modules\Crm\Services;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PRO-05: clients of a psychologist computed from therapy_sessions (the booker and the second participant of a pair
 * session), with marks from client_cards. Only the name or pseudonym is shown: no contacts (BR-PSY-05).
 */
class ClientDirectory
{
    public const STATUSES = ['active', 'no_upcoming', 'finished', 'changed'];

    public function __construct(private ClientRelationship $relationship) {}

    public function paginate(Psychologist $psychologist, ?string $search = null, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = DB::query()->fromSub($this->rows($psychologist), 'x')
            ->when($search !== null && trim($search) !== '', fn (Builder $q) => $q->where('x.name', 'ilike', '%'.addcslashes(trim($search), '%_\\').'%'))
            ->when($status !== null && in_array($status, self::STATUSES, true), fn (Builder $q) => $q->where('x.relation_status', $status))
            ->orderByRaw('x.next_session_at is null, x.next_session_at asc, x.last_session_at desc nulls last, x.name asc');

        $page = $query->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->present($row)));

        return $page;
    }

    /** @return array<string, mixed>|null */
    public function find(Psychologist $psychologist, string $clientId): ?array
    {
        $row = DB::query()->fromSub($this->rows($psychologist), 'x')->where('x.client_id', $clientId)->first();

        return $row ? $this->present($row) : null;
    }

    /**
     * Requests the client chose when booking this psychologist — "сведения о состоянии", visible only here.
     *
     * @return list<array{id: string, title: string, format: string}>
     */
    public function requests(Psychologist $psychologist, string $clientId): array
    {
        $ids = $this->relationship->sessionsOfPair($psychologist->id, $clientId)
            ->whereNotNull('client_request_ids')
            ->pluck('client_request_ids')
            ->flatten()->filter(fn ($id) => is_string($id))->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return ClientRequest::whereIn('id', $ids)->orderBy('carousel_sort')->get(['id', 'title', 'format'])
            ->map(fn (ClientRequest $r) => ['id' => $r->id, 'title' => $r->title, 'format' => $r->format])->values()->all();
    }

    /** One row per client of the psychologist with aggregated session data and the relation status. */
    private function rows(Psychologist $psychologist): Builder
    {
        $columns = ['status', 'starts_at', 'ends_at', 'created_at', 'paid_at', 'rescheduled_from_id', 'format'];
        $booker = DB::table('therapy_sessions')->select(['client_id as user_id', ...$columns])->where('psychologist_id', $psychologist->id);
        $partner = DB::table('therapy_sessions')->select(['partner_user_id as user_id', ...$columns])
            ->where('psychologist_id', $psychologist->id)->whereNotNull('partner_user_id');

        $active = TherapySession::ACTIVE_STATUSES;
        $diary = ClientRelationship::DIARY_STATUSES;
        $in = fn (array $list) => implode(',', array_fill(0, count($list), '?'));

        $aggregated = DB::query()->fromSub($booker->unionAll($partner), 'ts');
        $this->relationship->qualifying($aggregated, 'ts.');
        $aggregated->groupBy('ts.user_id')->selectRaw(
            'ts.user_id,
            min(ts.starts_at) as first_session_at,
            count(*) filter (where ts.status = ?) as held_count,
            max(ts.starts_at) filter (where ts.status = ?) as last_session_at,
            min(ts.starts_at) filter (where ts.status in ('.$in($active).') and ts.ends_at > ?) as next_session_at,
            count(*) filter (where ts.status in ('.$in($active).') and ts.ends_at > ?) as upcoming_count,
            max(ts.created_at) filter (where ts.rescheduled_from_id is null and ts.status in ('.$in($diary).')) as last_booked_at,
            bool_or(ts.status in ('.$in($diary).')) as diary_eligible,
            bool_or(ts.format = ?) as has_pair',
            [TherapySession::HELD, TherapySession::HELD, ...$active, now(), ...$active, now(), ...$diary, ...$diary, 'pair'],
        );

        $resumed = '(c.access_until is null or (a.last_booked_at is not null and a.last_booked_at > c.access_until))';

        return DB::query()->fromSub($aggregated, 'a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('client_cards as c', fn ($join) => $join->on('c.client_id', '=', 'a.user_id')->where('c.psychologist_id', '=', $psychologist->id))
            ->select([
                'a.user_id as client_id', 'u.name', 'u.timezone', 'a.first_session_at', 'a.last_session_at', 'a.next_session_at',
                'a.held_count', 'a.upcoming_count', 'a.diary_eligible', 'a.has_pair', 'c.work_finished_at', 'c.changed_psychologist_at',
            ])
            ->selectRaw("case when {$resumed} then null else c.access_until end as access_until")
            ->selectRaw("case
                when not {$resumed} and c.changed_psychologist_at is not null then 'changed'
                when not {$resumed} then 'finished'
                when a.next_session_at is not null then 'active'
                else 'no_upcoming' end as relation_status");
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $iso = fn ($v) => $v === null ? null : CarbonImmutable::parse($v, 'UTC')->toIso8601String();
        $status = $row->relation_status;

        return [
            'client_id' => $row->client_id,
            'name' => $row->name,
            'timezone' => $row->timezone,
            'status' => $status,
            'first_session_at' => $iso($row->first_session_at),
            'last_session_at' => $iso($row->last_session_at),
            'next_session_at' => $iso($row->next_session_at),
            'held_count' => (int) $row->held_count,
            'upcoming_count' => (int) $row->upcoming_count,
            'has_pair_sessions' => (bool) $row->has_pair,
            'diary_available' => (bool) $row->diary_eligible,
            'access_until' => $iso($row->access_until),
            'work_finished_at' => in_array($status, ['finished', 'changed'], true) ? $iso($row->work_finished_at) : null,
            'changed_psychologist_at' => $status === 'changed' ? $iso($row->changed_psychologist_at) : null,
        ];
    }
}
