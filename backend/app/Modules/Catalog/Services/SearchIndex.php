<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Support\Facades\DB;

/**
 * SEARCH for the psychologist catalog: a maintained tsvector over the PUBLISHED profile (pending changes are not
 * indexed until approved, BR-PSY-05). Weights: A — name, B — headline, approaches (with explanations) and requests,
 * C — specializations and education, D — "about". Russian text search configuration (stemming).
 */
class SearchIndex
{
    public const CONFIG = 'russian';

    private const VECTOR_SQL = <<<'SQL'
        setweight(to_tsvector('russian', coalesce(p.first_name, '') || ' ' || coalesce(p.last_name, '')), 'A')
        || setweight(to_tsvector('russian', coalesce(p.headline, '')), 'B')
        || setweight(to_tsvector('russian', coalesce((
            SELECT string_agg(a.title || ' ' || coalesce(ap.explanation, ''), ' ')
            FROM approach_psychologist ap JOIN approaches a ON a.id = ap.approach_id
            WHERE ap.psychologist_id = p.id), '')), 'B')
        || setweight(to_tsvector('russian', coalesce((
            SELECT string_agg(r.title, ' ')
            FROM client_request_psychologist cr JOIN client_requests r ON r.id = cr.client_request_id
            WHERE cr.psychologist_id = p.id), '')), 'B')
        || setweight(to_tsvector('russian', coalesce((
            SELECT string_agg(s.title, ' ')
            FROM psychologist_specialization ps JOIN specializations s ON s.id = ps.specialization_id
            WHERE ps.psychologist_id = p.id), '')), 'C')
        || setweight(to_tsvector('russian', coalesce((
            SELECT string_agg(coalesce(e->>'institution', '') || ' ' || coalesce(e->>'specialty', ''), ' ')
            FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p.education) = 'array' THEN p.education ELSE '[]'::jsonb END) e), '')), 'C')
        || setweight(to_tsvector('russian', coalesce(p.about, '')), 'D')
        SQL;

    public function refresh(Psychologist|string $psychologist): void
    {
        $id = $psychologist instanceof Psychologist ? $psychologist->getKey() : $psychologist;
        DB::update('UPDATE psychologists p SET search_vector = '.self::VECTOR_SQL.' WHERE p.id = ?', [$id]);
    }

    /** Rebuild every vector and re-derive stored price categories (bounds are edited in ADM-13). */
    public function refreshAll(): int
    {
        $count = DB::update('UPDATE psychologists p SET search_vector = '.self::VECTOR_SQL);

        $categories = PriceCategory::orderBy('sort')->get();
        Psychologist::withTrashed()->select(['id', 'price_individual', 'price_category_id'])->chunkById(200, function ($chunk) use ($categories) {
            foreach ($chunk as $p) {
                $category = $p->price_individual === null ? null
                    : $categories->first(fn ($c) => $p->price_individual >= $c->min_price && ($c->max_price === null || $p->price_individual <= $c->max_price));
                if ($category?->id !== $p->price_category_id) {
                    DB::table('psychologists')->where('id', $p->id)->update(['price_category_id' => $category?->id]);
                }
            }
        });

        return $count;
    }

    /**
     * Prefix query for type-ahead: "тревож выгор" → "тревож:* & выгор:*" (each word stemmed by the Russian config).
     * Returns null when nothing searchable is left after sanitising.
     */
    public static function prefixQuery(string $q): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice(array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 2)), 0, 8);
        if ($words === []) {
            return null;
        }

        return implode(' & ', array_map(fn ($w) => $w.':*', $words));
    }
}
