<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\QualificationDocument;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Public representation of a psychologist (SITE-02, SITE-03). Only published values are shown — pending changes
 * stay hidden until approved (BR-PSY-05). Documents are listed by title only, never as files. No ratings (DEC-32).
 */
class PsychologistPresenter
{
    /** @var Collection<int, PriceCategory>|null */
    private static ?Collection $categories = null;

    public static function card(Psychologist $p, ?CarbonImmutable $nearest, string $format): array
    {
        $category = self::category($p->price_individual);

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->fullName(),
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'gender' => $p->gender,
            'age' => $p->age(),
            'photo_url' => $p->photo?->url(),
            'headline' => $p->headline,
            'experience_years' => $p->experience_years,
            'approaches' => $p->approaches->sortBy('sort')->values()->map(fn ($a) => ['slug' => $a->slug, 'title' => $a->title])->all(),
            'specializations' => $p->specializations->sortBy('sort')->values()->map(fn ($s) => ['slug' => $s->slug, 'title' => $s->title])->all(),
            'requests' => $p->requests->sortBy('carousel_sort')->values()->map(fn ($r) => [
                'slug' => $r->slug, 'title' => $r->title, 'format' => $r->format, 'path' => $r->landingPath(),
            ])->all(),
            'works_individual' => (bool) $p->works_individual,
            'works_pair' => (bool) $p->works_pair,
            'price_individual' => $p->works_individual ? $p->price_individual : null,
            'price_pair' => $p->works_pair ? $p->price_pair : null,
            'price_category' => $category ? ['code' => $category->code, 'title' => $category->title] : null,
            'nearest_slot' => $nearest?->toIso8601ZuluString(),
            'nearest_slot_format' => $format,
            'has_video' => $p->video_approved_file_id !== null,
            'timezone' => $p->timezone,
            'is_active' => true,
        ];
    }

    public static function profile(Psychologist $p, ?CarbonImmutable $nearest, string $format): array
    {
        $documents = $p->documents->where('status', 'approved')->sortBy(fn (QualificationDocument $d) => [array_search($d->kind, Psychologist::DOCUMENT_KINDS, true), -(int) $d->year]);

        return [
            ...self::card($p, $nearest, $format),
            'about' => $p->about,
            'education' => array_values(array_map(fn ($e) => [
                'institution' => $e['institution'] ?? null, 'specialty' => $e['specialty'] ?? null, 'year' => $e['year'] ?? null,
            ], (array) ($p->education ?? []))),
            // "Подходы с пояснениями": the dictionary description plus how this psychologist applies the approach.
            'approaches' => $p->approaches->sortBy('sort')->values()->map(fn ($a) => [
                'slug' => $a->slug, 'title' => $a->title, 'description' => $a->explanation, 'explanation' => $a->pivot->explanation,
            ])->all(),
            'documents' => $documents->values()->map(fn (QualificationDocument $d) => [
                'kind' => $d->kind, 'title' => $d->title, 'institution' => $d->institution, 'specialty' => $d->specialty, 'year' => $d->year,
            ])->all(),
            'verified' => true,
            'verified_at' => $p->qualified_at?->toIso8601ZuluString(),
            'video_url' => $p->approvedVideo?->url(),
            'session_durations' => [
                'individual' => Settings::int('P-SESSION-DURATION-IND'),
                'pair' => Settings::int('P-SESSION-DURATION-PAIR'),
            ],
        ];
    }

    /** SITE-03, inactive variant: minimal information, no prices, slots or booking. */
    public static function inactive(Psychologist $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->fullName(),
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'photo_url' => $p->photo?->url(),
            'headline' => $p->headline,
            'is_active' => false,
        ];
    }

    public static function category(?int $price): ?PriceCategory
    {
        if ($price === null) {
            return null;
        }
        self::$categories ??= PriceCategory::orderBy('sort')->get();

        return self::$categories->first(fn (PriceCategory $c) => $price >= $c->min_price && ($c->max_price === null || $price <= $c->max_price));
    }

    /** Reset the per-request category memo (bounds may change between requests in long-running workers and tests). */
    public static function flush(): void
    {
        self::$categories = null;
    }
}
