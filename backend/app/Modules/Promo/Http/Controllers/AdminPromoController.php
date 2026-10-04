<?php

namespace App\Modules\Promo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Models\PromoRedemption;
use App\Modules\Promo\Services\PromoAdminService;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Settings\Settings;
use App\Support\StateMachine\StateTransition;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** ADM-09: promo codes — list and filters, create and edit, publish, deactivate, statistics, referral settings. */
class AdminPromoController extends Controller
{
    public const REFERRAL_SETTINGS = [
        'friend_discount' => 'P-REFERRAL-FRIEND-DISCOUNT',
        'reward_type' => 'P-REFERRAL-REWARD-TYPE',
        'reward_value' => 'P-REFERRAL-REWARD-VALUE',
        'validity_days' => 'P-REFERRAL-CODE-VALIDITY',
    ];

    public function __construct(private PromoAdminService $promos) {}

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(PromoCode::STATUS_LABELS))],
            'type' => ['nullable', Rule::in(array_keys(PromoCode::TYPE_LABELS))],
            'kind' => ['nullable', Rule::in(array_keys(PromoCode::KIND_LABELS))],
            'source' => ['nullable', Rule::in(array_keys(PromoCode::SOURCE_LABELS))],
            'batch_id' => ['nullable', 'uuid'],
        ]);
        $page = PromoCode::query()
            ->with(['batch', 'owner'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('batch_id'), fn ($q, $v) => $q->where('promo_batch_id', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v), fn ($q) => $request->query('batch_id') ? $q : $q->where('source', '!=', 'batch'))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('code', 'ilike', '%'.mb_strtoupper($v).'%')->orWhere('title', 'ilike', "%{$v}%")))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 30), 100));
        $stats = $this->promos->statsFor($page->getCollection()->pluck('id'));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PromoCode $p) => self::row($p, $stats[$p->id] ?? PromoAdminService::emptyStats()))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeCode($request);
        $data = $request->validate($this->rules($request, true));
        $promo = $this->promos->create($data, $request->user());

        return response()->json(['data' => $this->detail($promo)], 201);
    }

    public function show(PromoCode $promo)
    {
        return response()->json(['data' => $this->detail($promo)]);
    }

    public function update(Request $request, PromoCode $promo)
    {
        $this->normalizeCode($request);
        $data = $request->validate($this->rules($request, false, $promo));
        $promo = $this->promos->update($promo, $data, $request->user());

        return response()->json(['data' => $this->detail($promo)]);
    }

    public function publish(Request $request, PromoCode $promo)
    {
        return response()->json(['data' => $this->detail($this->promos->publish($promo, $request->user()))]);
    }

    public function deactivate(Request $request, PromoCode $promo)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Укажите причину деактивации.']);

        return response()->json(['data' => $this->detail($this->promos->deactivate($promo, $data['reason'], $request->user()))]);
    }

    public function overview()
    {
        return response()->json(['data' => $this->promos->overview()]);
    }

    /** Options for the restriction pickers in the form. */
    public function lookups(Request $request)
    {
        $users = $request->query('q')
            ? User::where(fn ($w) => $w->where('email', 'ilike', '%'.$request->query('q').'%')->orWhere('name', 'ilike', '%'.$request->query('q').'%'))
                ->limit(10)->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->fullName(), 'email' => $u->email])->all()
            : [];

        return response()->json(['data' => [
            'psychologists' => Psychologist::where('qualification_status', 'approved')->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (Psychologist $p) => ['id' => $p->id, 'name' => $p->fullName()])->all(),
            'price_categories' => PriceCategory::orderBy('sort')->get(['id', 'title'])->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])->all(),
            'users' => $users,
        ]]);
    }

    public function referralSettings()
    {
        return response()->json(['data' => collect(self::REFERRAL_SETTINGS)->map(fn ($key) => Settings::get($key))->all()]);
    }

    public function updateReferralSettings(Request $request)
    {
        $data = $request->validate([
            'friend_discount' => ['required', 'integer', 'min:1', 'max:100'],
            'reward_type' => ['required', Rule::in(['fixed', 'percent'])],
            'reward_value' => ['required', 'integer', 'min:1', function (string $attr, mixed $value, Closure $fail) use ($request) {
                if ($request->input('reward_type') === 'percent' && (int) $value > 100) {
                    $fail('Процент скидки — от 1 до 100.');
                }
            }],
            'validity_days' => ['required', 'integer', 'min:1', 'max:730'],
        ]);
        $before = collect(self::REFERRAL_SETTINGS)->map(fn ($key) => Settings::get($key))->all();
        foreach (self::REFERRAL_SETTINGS as $field => $key) {
            Settings::set($key, $data[$field], $request->user()->id);
        }
        Audit::log('ADM-09', 'promo.referral_settings_updated', null, ['before' => $before, 'after' => $data]);

        return $this->referralSettings();
    }

    public static function row(PromoCode $p, array $stats): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'title' => $p->title,
            'description' => $p->description,
            'type' => $p->type,
            'type_label' => PromoCode::TYPE_LABELS[$p->type] ?? $p->type,
            'value' => (int) $p->value,
            'discount_label' => $p->discountLabel(),
            'kind' => $p->kind,
            'kind_label' => PromoCode::KIND_LABELS[$p->kind] ?? $p->kind,
            'source' => $p->source,
            'source_label' => PromoCode::SOURCE_LABELS[$p->source] ?? $p->source,
            'batch' => $p->relationLoaded('batch') && $p->batch ? ['id' => $p->batch->id, 'title' => $p->batch->title] : null,
            'owner' => $p->relationLoaded('owner') && $p->owner ? ['id' => $p->owner->id, 'name' => $p->owner->fullName(), 'email' => $p->owner->email] : null,
            'valid_from' => $p->valid_from?->toIso8601String(),
            'valid_until' => $p->valid_until?->toIso8601String(),
            'total_limit' => $p->total_limit !== null ? (int) $p->total_limit : null,
            'per_user_limit' => $p->per_user_limit !== null ? (int) $p->per_user_limit : null,
            'min_amount' => $p->min_amount !== null ? (int) $p->min_amount : null,
            'restrictions' => $p->restrictions ?? (object) [],
            'status' => $p->status,
            'status_label' => PromoCode::STATUS_LABELS[$p->status] ?? $p->status,
            'uses_count' => (int) $p->uses_count,
            'published_at' => $p->published_at?->toIso8601String(),
            'deactivated_at' => $p->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $p->deactivation_reason,
            'created_at' => $p->created_at?->toIso8601String(),
            'stats' => $stats,
        ];
    }

    private function detail(PromoCode $promo): array
    {
        $promo->load(['batch', 'owner']);
        $stats = $this->promos->statsFor([$promo->id])[$promo->id];

        return [
            ...self::row($promo, $stats),
            'editable' => $promo->status === 'draft' ? [...PromoAdminService::TERMS, ...PromoAdminService::PUBLISHED_EDITABLE] : (in_array($promo->status, ['expired', 'deactivated'], true) ? [] : PromoAdminService::PUBLISHED_EDITABLE),
            'redemptions' => PromoRedemption::with(['user', 'session'])->where('promo_code_id', $promo->id)->latest()->limit(50)->get()->map(fn (PromoRedemption $r) => [
                'id' => $r->id,
                'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->fullName(), 'email' => $r->user->email] : null,
                'session_starts_at' => $r->session?->starts_at?->toIso8601String(),
                'discount_amount' => (int) $r->discount_amount,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'applied_at' => $r->applied_at?->toIso8601String(),
                'restored_at' => $r->restored_at?->toIso8601String(),
            ])->all(),
            'history' => StateTransition::where('model_type', $promo->getMorphClass())->where('model_id', $promo->id)->orderBy('created_at')->get()
                ->map(fn (StateTransition $t) => ['from' => $t->from, 'to' => $t->to, 'reason' => $t->reason, 'at' => $t->created_at?->toIso8601String()])->all(),
        ];
    }

    private function normalizeCode(Request $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => PromoCode::normalize($request->input('code'))]);
        }
    }

    private function rules(Request $request, bool $creating, ?PromoCode $promo = null): array
    {
        $type = $request->input('type', $promo?->type);

        return [
            'code' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[A-Z0-9_-]+$/u', Rule::unique('promo_codes', 'code')->ignore($promo?->id)],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(PromoCode::TYPE_LABELS))],
            'value' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', function (string $attr, mixed $value, Closure $fail) use ($type) {
                if (in_array($type, ['percent', 'first_session'], true) && (int) $value > 100) {
                    $fail('Процент скидки — от 1 до 100.');
                }
            }],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(PromoCode::KIND_LABELS))],
            'owner_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => array_values(array_filter(['nullable', 'date', $request->filled('valid_from') ? 'after:valid_from' : null])),
            'total_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'min_amount' => ['nullable', 'integer', 'min:0'],
            'restrictions' => ['nullable', 'array'],
            'restrictions.service_types' => ['nullable', 'array'],
            'restrictions.service_types.*' => [Rule::in(['individual', 'pair'])],
            'restrictions.psychologist_ids' => ['nullable', 'array'],
            'restrictions.psychologist_ids.*' => ['uuid', Rule::exists('psychologists', 'id')],
            'restrictions.price_category_ids' => ['nullable', 'array'],
            'restrictions.price_category_ids.*' => ['uuid', Rule::exists('price_categories', 'id')],
            'restrictions.segment' => ['nullable', 'array'],
            'restrictions.segment.new_clients' => ['nullable', 'boolean'],
            'restrictions.segment.registered_after' => ['nullable', 'date_format:Y-m-d'],
            'restrictions.segment.min_held_sessions' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'publish' => ['nullable', 'boolean'],
        ];
    }
}
