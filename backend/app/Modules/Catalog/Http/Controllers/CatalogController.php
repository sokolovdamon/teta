<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\PsychologistPresenter;
use App\Modules\Catalog\Services\CatalogQuery;
use App\Modules\Catalog\Services\NearestSlots;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Services\SlotService;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** CATALOG and SEARCH: public catalog (SITE-02), profile page (SITE-03) and free slots. */
class CatalogController extends Controller
{
    public function __construct(private CatalogQuery $catalog, private NearestSlots $nearest)
    {
        PsychologistPresenter::flush();
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'requests' => ['sometimes', 'array', 'max:43'], 'requests.*' => ['string', 'max:128'],
            'approaches' => ['sometimes', 'array', 'max:50'], 'approaches.*' => ['string', 'max:128'],
            'specializations' => ['sometimes', 'array', 'max:50'], 'specializations.*' => ['string', 'max:128'],
            'gender' => ['nullable', Rule::in(['female', 'male'])],
            'age_min' => ['nullable', 'integer', 'min:18', 'max:100'],
            'age_max' => ['nullable', 'integer', 'min:18', 'max:100', 'gte:age_min'],
            'price_category' => ['nullable'], 'price_category.*' => ['string', 'max:64'],
            'format' => ['nullable', Rule::in(['individual', 'pair'])],
            'available_within_days' => ['nullable', 'integer', 'min:1', 'max:'.max(1, Settings::int('P-BOOK-HORIZON'))],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(CatalogQuery::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $result = $this->catalog->search($f);

        return response()->json([
            'data' => $result['items']->map(fn (Psychologist $p) => PsychologistPresenter::card(
                $p, $result['nearest'][$p->id] ?? null, $this->catalog->cardFormat($p, $f['format'] ?? null),
            ))->values(),
            'meta' => [
                'current_page' => $result['page'], 'last_page' => $result['last_page'],
                'per_page' => $result['per_page'], 'total' => $result['total'],
            ],
        ]);
    }

    /** SITE-03: active profile, minimal inactive variant, 404 for profiles that were never approved. */
    public function show(string $slug): JsonResponse
    {
        $p = Psychologist::where('slug', $slug)->first();
        abort_if(! $p || ! $p->wasEverApproved(), 404, 'Психолог не найден.');

        if (! $p->isBookable()) {
            $p->load('photo');

            return response()->json(['data' => PsychologistPresenter::inactive($p)]);
        }

        $p->load(['photo', 'approvedVideo', 'approaches', 'requests', 'specializations', 'documents']);
        $format = $this->catalog->cardFormat($p, null);

        return response()->json(['data' => PsychologistPresenter::profile($p, $this->nearest->get($p, $format), $format)]);
    }

    /** Page view counter; called once per page view by the browser (SSR fetches are cached and would miscount). */
    public function view(string $slug): JsonResponse
    {
        $updated = DB::table('psychologists')->where('slug', $slug)->whereNull('deleted_at')->increment('views_count');
        abort_if($updated === 0, 404);

        return response()->json(['ok' => true]);
    }

    /** Free slots in UTC (the browser shows them in the visitor's timezone). */
    public function slots(Request $request, string $slug, SlotService $slots): JsonResponse
    {
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['individual', 'pair'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $p = Psychologist::where('slug', $slug)->first();
        abort_if(! $p || ! $p->wasEverApproved(), 404, 'Психолог не найден.');

        $format = $data['format'] ?? $this->catalog->cardFormat($p, null);
        $now = CarbonImmutable::now();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : $now;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : $from->addDays(14);
        if ($to->diffInDays($from, true) > 62) {
            $to = $from->addDays(62);
        }

        $works = $format === 'pair' ? $p->works_pair && $p->price_pair !== null : $p->works_individual && $p->price_individual !== null;
        $list = $p->isBookable() && $works ? $slots->availableSlots($p, $format, $from, $to) : [];

        return response()->json(['data' => [
            'is_active' => $p->isBookable(),
            'format' => $format,
            'duration_min' => $slots->duration($format),
            'timezone' => $p->timezone,
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
            'slots' => array_map(fn (CarbonImmutable $s) => $s->toIso8601ZuluString(), $list),
        ]]);
    }
}
