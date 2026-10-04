<?php

namespace App\Modules\Promo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Promo\Models\PromoBatch;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Services\PromoAdminService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ADM-09: batches of N unique individual codes — generation, publication, deactivation, CSV export, statistics. */
class AdminPromoBatchController extends Controller
{
    public const MAX_SIZE = 5000;

    public function __construct(private PromoAdminService $promos) {}

    public function index(Request $request)
    {
        $page = PromoBatch::with('creator')->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PromoBatch $b) => $this->row($b))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request)
    {
        $type = $request->input('type');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'size' => ['required', 'integer', 'min:1', 'max:'.self::MAX_SIZE],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'type' => ['required', Rule::in(array_keys(PromoCode::TYPE_LABELS))],
            'value' => ['required', 'integer', 'min:1', function (string $attr, mixed $value, Closure $fail) use ($type) {
                if (in_array($type, ['percent', 'first_session'], true) && (int) $value > 100) {
                    $fail('Процент скидки — от 1 до 100.');
                }
            }],
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
        ]);
        $batch = $this->promos->createBatch($data, $request->user());

        return response()->json(['data' => $this->row($batch->fresh('creator'))], 201);
    }

    public function show(PromoBatch $batch)
    {
        $batch->load('creator');
        $codes = PromoCode::where('promo_batch_id', $batch->id)->orderBy('code')->limit(200)->get();
        $stats = $this->promos->statsFor($codes->pluck('id'));

        return response()->json(['data' => [
            ...$this->row($batch),
            'codes' => $codes->map(fn (PromoCode $p) => AdminPromoController::row($p, $stats[$p->id] ?? PromoAdminService::emptyStats()))->all(),
            'codes_shown' => $codes->count(),
        ]]);
    }

    public function publish(Request $request, PromoBatch $batch)
    {
        $count = $this->promos->publishBatch($batch, $request->user());

        return response()->json(['data' => $this->row($batch->fresh('creator')), 'affected' => $count]);
    }

    public function deactivate(Request $request, PromoBatch $batch)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Укажите причину деактивации.']);
        $count = $this->promos->deactivateBatch($batch, $data['reason'], $request->user());

        return response()->json(['data' => $this->row($batch->fresh('creator')), 'affected' => $count]);
    }

    /** CSV (UTF-8 with BOM, ";") with every code of the batch and its usage. */
    public function export(Request $request, PromoBatch $batch): StreamedResponse
    {
        Audit::log('ADM-09', 'promo.batch_exported', $batch, ['size' => (int) $batch->size]);
        $tz = config('platform.timezone');

        return response()->streamDownload(function () use ($batch, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Код', 'Скидка', 'Статус', 'Действует с', 'Действует до', 'Применений', 'Восстановлено', 'Сумма скидок, ₽'], ';');
            PromoCode::where('promo_batch_id', $batch->id)->orderBy('code')->chunk(500, function ($chunk) use ($out, $tz) {
                $stats = $this->promos->statsFor($chunk->pluck('id'));
                foreach ($chunk as $p) {
                    $s = $stats[$p->id];
                    fputcsv($out, [
                        $p->code,
                        $p->discountLabel(),
                        PromoCode::STATUS_LABELS[$p->status] ?? $p->status,
                        $p->valid_from?->setTimezone($tz)->format('d.m.Y H:i') ?? '',
                        $p->valid_until?->setTimezone($tz)->format('d.m.Y H:i') ?? '',
                        $s['applied'],
                        $s['restored'],
                        number_format($s['discount_sum'] / 100, 2, ',', ''),
                    ], ';');
                }
            });
            fclose($out);
        }, 'promo-batch-'.($batch->prefix ? mb_strtolower($batch->prefix).'-' : '').substr($batch->id, 0, 8).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function row(PromoBatch $batch): array
    {
        $byStatus = PromoCode::where('promo_batch_id', $batch->id)->selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->map(fn ($v) => (int) $v)->all();
        $sample = PromoCode::where('promo_batch_id', $batch->id)->first();
        $totals = DB::table('promo_redemptions')
            ->join('promo_codes', 'promo_codes.id', '=', 'promo_redemptions.promo_code_id')
            ->where('promo_codes.promo_batch_id', $batch->id)
            ->selectRaw("count(*) as reserved_total, count(*) filter (where promo_redemptions.status = 'applied') as applied, coalesce(sum(promo_redemptions.discount_amount) filter (where promo_redemptions.status = 'applied'), 0) as discount_sum")
            ->first();
        $reserved = (int) ($totals->reserved_total ?? 0);
        $applied = (int) ($totals->applied ?? 0);

        return [
            'id' => $batch->id,
            'title' => $batch->title,
            'description' => $batch->description,
            'prefix' => $batch->prefix,
            'size' => (int) $batch->size,
            'created_at' => $batch->created_at?->toIso8601String(),
            'created_by' => $batch->creator?->fullName(),
            'terms' => $sample ? [
                'type' => $sample->type,
                'type_label' => PromoCode::TYPE_LABELS[$sample->type] ?? $sample->type,
                'value' => (int) $sample->value,
                'discount_label' => $sample->discountLabel(),
                'valid_from' => $sample->valid_from?->toIso8601String(),
                'valid_until' => $sample->valid_until?->toIso8601String(),
                'total_limit' => $sample->total_limit !== null ? (int) $sample->total_limit : null,
                'per_user_limit' => $sample->per_user_limit !== null ? (int) $sample->per_user_limit : null,
                'min_amount' => $sample->min_amount !== null ? (int) $sample->min_amount : null,
                'restrictions' => $sample->restrictions ?? (object) [],
            ] : null,
            'codes_by_status' => $byStatus,
            'stats' => [
                'reserved_total' => $reserved,
                'applied' => $applied,
                'discount_sum' => (int) ($totals->discount_sum ?? 0),
                'conversion' => $reserved > 0 ? round($applied * 100 / $reserved, 1) : null,
            ],
        ];
    }
}
