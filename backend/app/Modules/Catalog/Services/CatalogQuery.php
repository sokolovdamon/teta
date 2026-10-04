<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Dictionaries\Models\Specialization;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * CATALOG (SITE-02): only bookable profiles (Psychologist::scopeBookable — approved, published, work status
 * "active", not inactive by supervision). Filters:
 *  - requests[]        — each selected request must be covered (ids or slugs);
 *  - approaches[]      — any of the selected approaches;
 *  - specializations[] — any of the selected specializations;
 *  - gender, age_min / age_max (by birth year), price_category (code or id, one or several; by the individual price,
 *    DEC-55), format (individual | pair), available_within_days (has a free slot), q (full-text, Russian).
 * Sorting: nearest slot (default), price_asc, price_desc, experience, relevance (with q). No ratings (DEC-32).
 */
class CatalogQuery
{
    public const SORTS = ['nearest', 'price_asc', 'price_desc', 'experience', 'relevance'];

    public function __construct(private NearestSlots $nearest) {}

    /**
     * @param  array<string, mixed>  $f  validated filters
     * @return array{items: Collection<int, Psychologist>, nearest: array<string, ?CarbonImmutable>, total: int, page: int, per_page: int, last_page: int}
     */
    public function search(array $f): array
    {
        $query = $this->filtered($f);
        $tsQuery = isset($f['q']) ? SearchIndex::prefixQuery((string) $f['q']) : null;
        if ($tsQuery !== null) {
            $query->selectRaw('psychologists.*, ts_rank(search_vector, to_tsquery(?, ?)) AS search_rank', [SearchIndex::CONFIG, $tsQuery]);
        }

        /** @var Collection<int, Psychologist> $all */
        $all = $query->get();

        $nearest = [];
        foreach ($all as $p) {
            $nearest[$p->id] = $this->nearest->get($p, $this->cardFormat($p, $f['format'] ?? null));
        }

        if (! empty($f['available_within_days'])) {
            $limit = CarbonImmutable::now()->addDays((int) $f['available_within_days']);
            $all = $all->filter(fn (Psychologist $p) => $nearest[$p->id] !== null && $nearest[$p->id] <= $limit)->values();
        }

        $sort = $f['sort'] ?? 'nearest';
        if ($sort === 'relevance' && $tsQuery === null) {
            $sort = 'nearest';
        }
        $all = $this->sorted($all, $sort, $nearest, $f['format'] ?? null);

        $perPage = (int) ($f['per_page'] ?? 12);
        $total = $all->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $lastPage);
        $items = $all->slice(($page - 1) * $perPage, $perPage)->values();
        $items->load(['photo', 'approvedVideo', 'approaches', 'requests', 'specializations']);

        return ['items' => $items, 'nearest' => $nearest, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage];
    }

    /** @param  array<string, mixed>  $f */
    public function filtered(array $f): Builder
    {
        $query = Psychologist::query()->bookable();

        foreach ($this->resolveIds(ClientRequest::class, $f['requests'] ?? []) as $ids) {
            $query->whereHas('requests', fn (Builder $q) => $q->whereIn('client_requests.id', $ids));
        }
        $approachIds = $this->resolveIds(Approach::class, $f['approaches'] ?? [])->flatten()->all();
        if (($f['approaches'] ?? []) !== []) {
            $query->whereHas('approaches', fn (Builder $q) => $q->whereIn('approaches.id', $approachIds ?: ['00000000-0000-0000-0000-000000000000']));
        }
        $specIds = $this->resolveIds(Specialization::class, $f['specializations'] ?? [])->flatten()->all();
        if (($f['specializations'] ?? []) !== []) {
            $query->whereHas('specializations', fn (Builder $q) => $q->whereIn('specializations.id', $specIds ?: ['00000000-0000-0000-0000-000000000000']));
        }

        if (! empty($f['gender'])) {
            $query->where('gender', $f['gender']);
        }
        $year = (int) now()->year;
        if (! empty($f['age_min'])) {
            $query->where('birth_year', '<=', $year - (int) $f['age_min']);
        }
        if (! empty($f['age_max'])) {
            $query->where('birth_year', '>=', $year - (int) $f['age_max']);
        }

        $categories = $this->categories($f['price_category'] ?? []);
        if ($categories !== null) {
            // By the bounds, not the stored id: bounds are edited in ADM-13 and must apply at once (DEC-55).
            $query->where(function (Builder $q) use ($categories) {
                $q->whereRaw('1 = 0');
                foreach ($categories as $c) {
                    $q->orWhere(fn (Builder $w) => $w->where('price_individual', '>=', $c->min_price)
                        ->when($c->max_price !== null, fn ($w) => $w->where('price_individual', '<=', $c->max_price)));
                }
            });
        }

        $format = $f['format'] ?? null;
        if ($format === 'pair') {
            $query->where('works_pair', true)->whereNotNull('price_pair');
        } elseif ($format === 'individual') {
            $query->where('works_individual', true)->whereNotNull('price_individual');
        } else {
            $query->where(fn (Builder $q) => $q->where(fn ($w) => $w->where('works_individual', true)->whereNotNull('price_individual'))
                ->orWhere(fn ($w) => $w->where('works_pair', true)->whereNotNull('price_pair')));
        }

        if (isset($f['q']) && trim((string) $f['q']) !== '') {
            $tsQuery = SearchIndex::prefixQuery((string) $f['q']);
            $tsQuery === null
                ? $query->whereRaw('1 = 0')
                : $query->whereRaw('search_vector @@ to_tsquery(?, ?)', [SearchIndex::CONFIG, $tsQuery]);
        }

        return $query;
    }

    /** Format used for the card's price and nearest slot. */
    public function cardFormat(Psychologist $p, ?string $requested): string
    {
        if ($requested === 'pair' || $requested === 'individual') {
            return $requested;
        }

        return $p->works_individual && $p->price_individual !== null ? 'individual' : 'pair';
    }

    /**
     * @param  Collection<int, Psychologist>  $items
     * @param  array<string, ?CarbonImmutable>  $nearest
     * @return Collection<int, Psychologist>
     */
    private function sorted(Collection $items, string $sort, array $nearest, ?string $format): Collection
    {
        $price = fn (Psychologist $p) => $this->cardFormat($p, $format) === 'pair' ? (int) $p->price_pair : (int) $p->price_individual;
        $slot = fn (Psychologist $p) => $nearest[$p->id]?->getTimestamp() ?? PHP_INT_MAX;
        $name = fn (Psychologist $p) => mb_strtolower($p->fullName());

        $comparators = match ($sort) {
            'price_asc' => [fn ($a, $b) => $price($a) <=> $price($b)],
            'price_desc' => [fn ($a, $b) => $price($b) <=> $price($a)],
            'experience' => [fn ($a, $b) => (int) $b->experience_years <=> (int) $a->experience_years],
            'relevance' => [fn ($a, $b) => (float) $b->search_rank <=> (float) $a->search_rank],
            default => [],
        };
        $comparators[] = fn ($a, $b) => $slot($a) <=> $slot($b);
        $comparators[] = fn ($a, $b) => $name($a) <=> $name($b);

        return $items->sort(function ($a, $b) use ($comparators) {
            foreach ($comparators as $cmp) {
                $r = $cmp($a, $b);
                if ($r !== 0) {
                    return $r;
                }
            }

            return 0;
        })->values();
    }

    /**
     * Each value (id or slug) resolves to a group of ids; a slug may exist in both formats (DEC-11 landing pages).
     *
     * @param  class-string  $model
     * @param  list<string>|string  $values
     * @return Collection<int, list<string>>
     */
    private function resolveIds(string $model, array|string $values): Collection
    {
        $values = collect((array) $values)->filter(fn ($v) => is_string($v) && $v !== '')->unique()->values();
        if ($values->isEmpty()) {
            return collect();
        }
        $rows = $model::query()
            ->where(fn ($q) => $q->whereIn('id', $values->filter(fn ($v) => Str::isUuid($v))->values()->all())->orWhereIn('slug', $values->all()))
            ->get(['id', 'slug']);

        return $values->map(fn ($v) => $rows->filter(fn ($r) => $r->id === $v || $r->slug === $v)->pluck('id')->values()->all() ?: ['00000000-0000-0000-0000-000000000000']);
    }

    /**
     * @param  list<string>|string  $values
     * @return Collection<int, PriceCategory>|null
     */
    private function categories(array|string $values): ?Collection
    {
        $values = collect((array) $values)->filter(fn ($v) => is_string($v) && $v !== '')->values();
        if ($values->isEmpty()) {
            return null;
        }

        return PriceCategory::query()
            ->where(fn ($q) => $q->whereIn('code', $values->all())->orWhereIn('id', $values->filter(fn ($v) => Str::isUuid($v))->values()->all()))
            ->get();
    }
}
