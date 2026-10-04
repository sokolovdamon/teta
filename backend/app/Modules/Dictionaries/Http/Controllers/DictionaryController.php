<?php

namespace App\Modules\Dictionaries\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Dictionaries\Models\RequestGroup;
use App\Modules\Dictionaries\Models\ServiceType;
use App\Modules\Dictionaries\Models\Specialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Public dictionaries for the site, wizard and filters; admin CRUD in ADM-13 without code changes. */
class DictionaryController extends Controller
{
    private const MODELS = [
        'request-groups' => RequestGroup::class,
        'requests' => ClientRequest::class,
        'approaches' => Approach::class,
        'specializations' => Specialization::class,
        'service-types' => ServiceType::class,
        'price-categories' => PriceCategory::class,
    ];

    public function all()
    {
        $data = Cache::remember('dictionaries.public', 600, fn () => [
            'request_groups' => RequestGroup::with(['requests' => fn ($q) => $q->where('is_published', true)])->orderBy('sort')->get()
                ->map(fn ($g) => [
                    'id' => $g->id, 'slug' => $g->slug, 'title' => $g->title, 'format' => $g->format,
                    'requests' => $g->requests->map(fn ($r) => [
                        'id' => $r->id, 'slug' => $r->slug, 'title' => $r->title, 'format' => $r->format,
                        'age_label' => $r->age_label, 'path' => $r->landingPath(),
                    ])->values(),
                ])->values(),
            'approaches' => Approach::where('is_active', true)->orderBy('sort')->get(['id', 'slug', 'title', 'explanation']),
            'specializations' => Specialization::where('is_active', true)->orderBy('sort')->get(['id', 'slug', 'title']),
            'service_types' => ServiceType::where('is_active', true)->get(['id', 'code', 'title', 'duration_min']),
            'price_categories' => PriceCategory::orderBy('sort')->get(['id', 'code', 'title', 'min_price', 'max_price']),
        ]);

        return response()->json(['data' => $data]);
    }

    /** SITE-06: landing page of one request. */
    public function request(Request $http, string $slug)
    {
        $format = $http->query('format') === 'pair' ? 'pair' : 'individual';
        $request = ClientRequest::where('slug', $slug)->where('format', $format)->where('is_published', true)->with('group')->firstOrFail();

        return response()->json(['data' => [
            'id' => $request->id, 'slug' => $request->slug, 'title' => $request->title, 'format' => $request->format,
            'age_label' => $request->age_label, 'group' => ['slug' => $request->group->slug, 'title' => $request->group->title],
            'seo_title' => $request->seo_title, 'seo_description' => $request->seo_description,
            'landing_lead' => $request->landing_lead, 'landing_body' => $request->landing_body, 'path' => $request->landingPath(),
        ]]);
    }

    // ---- ADM-13 ---------------------------------------------------------------------------------

    public function adminIndex(string $type)
    {
        $class = $this->model($type);
        $query = $class::query();
        $query->orderBy(match ($type) {
            'requests' => 'carousel_sort',
            'service-types' => 'code',
            default => 'sort',
        });

        return response()->json(['data' => $query->get()]);
    }

    public function adminStore(Request $request, string $type)
    {
        $class = $this->model($type);
        $item = $class::create($request->validate($this->rules($type)));
        $this->forget();
        Audit::log('ADM-13', "dictionary.{$type}.created", $item);

        return response()->json(['data' => $item], 201);
    }

    public function adminUpdate(Request $request, string $type, string $id)
    {
        $class = $this->model($type);
        /** @var Model $item */
        $item = $class::findOrFail($id);
        $item->update($request->validate($this->rules($type, $item)));
        $this->forget();
        Audit::log('ADM-13', "dictionary.{$type}.updated", $item, $item->getChanges());

        return response()->json(['data' => $item]);
    }

    public function adminDestroy(string $type, string $id)
    {
        $class = $this->model($type);
        $item = $class::findOrFail($id);
        try {
            $item->delete();
        } catch (QueryException) {
            abort(422, 'Значение используется — снимите публикацию или сделайте неактивным.');
        }
        $this->forget();
        Audit::log('ADM-13', "dictionary.{$type}.deleted", $item);

        return response()->noContent();
    }

    /** @return class-string<Model> */
    private function model(string $type): string
    {
        return self::MODELS[$type] ?? abort(404);
    }

    private function forget(): void
    {
        Cache::forget('dictionaries.public');
    }

    private function rules(string $type, ?Model $item = null): array
    {
        $req = $item ? 'sometimes' : 'required';
        $table = (new (self::MODELS[$type]))->getTable();
        $unique = fn (string $col) => Rule::unique($table, $col)->ignore($item?->getKey());

        return match ($type) {
            'request-groups' => ['slug' => [$req, 'string', 'max:64', $unique('slug')], 'title' => [$req, 'string', 'max:255'], 'format' => ['sometimes', Rule::in(['individual', 'pair'])], 'sort' => ['sometimes', 'integer']],
            'requests' => [
                'request_group_id' => [$req, 'uuid', Rule::exists('request_groups', 'id')], 'title' => [$req, 'string', 'max:255'],
                'slug' => [$req, 'string', 'max:128', 'regex:/^[a-z0-9-]+$/'], 'format' => ['sometimes', Rule::in(['individual', 'pair'])],
                'age_label' => ['nullable', 'string', 'max:8'], 'carousel_sort' => ['sometimes', 'integer'], 'seo_title' => ['nullable', 'string', 'max:255'],
                'seo_description' => ['nullable', 'string', 'max:1000'], 'landing_lead' => ['nullable', 'string'], 'landing_body' => ['nullable', 'string'],
                'is_published' => ['sometimes', 'boolean'],
            ],
            'approaches' => ['slug' => [$req, 'string', 'max:64', $unique('slug')], 'title' => [$req, 'string', 'max:255'], 'explanation' => ['nullable', 'string'], 'sort' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']],
            'specializations' => ['slug' => [$req, 'string', 'max:64', $unique('slug')], 'title' => [$req, 'string', 'max:255'], 'sort' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']],
            'service-types' => ['code' => [$req, 'string', 'max:64', $unique('code')], 'title' => [$req, 'string', 'max:255'], 'duration_min' => [$req, 'integer', 'min:10', 'max:480'], 'is_active' => ['sometimes', 'boolean']],
            'price-categories' => ['code' => [$req, 'string', 'max:32', $unique('code')], 'title' => [$req, 'string', 'max:255'], 'min_price' => [$req, 'integer', 'min:0'], 'max_price' => ['nullable', 'integer', 'gt:min_price'], 'sort' => ['sometimes', 'integer']],
        };
    }
}
