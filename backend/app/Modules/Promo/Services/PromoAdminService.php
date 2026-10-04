<?php

namespace App\Modules\Promo\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Promo\Models\PromoBatch;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Models\PromoRedemption;
use App\Modules\Promo\Models\ReferralInvite;
use App\Support\Events\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADM-09 (Э8, ST-17): codes and batches, publication by schedule, deactivation, statistics (BR-PROMO-10).
 * Every admin action is written to the audit log.
 */
class PromoAdminService
{
    /** Fields that define the terms; they may change only while the code is a draft (BR-PROMO-13). */
    public const TERMS = ['code', 'type', 'value', 'kind', 'owner_user_id', 'valid_from', 'min_amount', 'restrictions'];

    /** Fields an admin may change on a published code: they never worsen the terms already issued. */
    public const PUBLISHED_EDITABLE = ['title', 'description', 'valid_until', 'total_limit', 'per_user_limit'];

    public function __construct(private PromoCodeGenerator $generator) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): PromoCode
    {
        $data = self::utcDates($data);
        $promo = DB::transaction(function () use ($data, $actor) {
            $promo = new PromoCode([
                ...Arr::only($data, ['title', 'description', 'type', 'value', 'kind', 'owner_user_id', 'valid_from', 'valid_until', 'total_limit', 'per_user_limit', 'min_amount']),
                'code' => ! empty($data['code']) ? PromoCode::normalize($data['code']) : $this->generator->one(),
                'restrictions' => $this->restrictions($data['restrictions'] ?? null),
                'source' => 'admin',
                'created_by' => $actor->id,
            ]);
            $promo->forceFill(['status' => 'draft'])->save();
            $promo->recordInitialState($actor->id);
            Audit::log('ADM-09', 'promo.created', $promo, $this->auditable($promo), userId: $actor->id);

            return $promo;
        });

        return ! empty($data['publish']) ? $this->publish($promo, $actor) : $promo->fresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(PromoCode $promo, array $data, User $actor): PromoCode
    {
        abort_if(in_array($promo->status, ['expired', 'deactivated'], true), 422, 'Истёкший или деактивированный промокод изменить нельзя.');

        $data = self::utcDates($data);

        return DB::transaction(function () use ($promo, $data, $actor) {
            $promo = PromoCode::whereKey($promo->id)->lockForUpdate()->firstOrFail();
            $allowed = $promo->status === 'draft' ? [...self::TERMS, ...self::PUBLISHED_EDITABLE] : self::PUBLISHED_EDITABLE;
            $changes = Arr::only($data, $allowed);
            if (array_key_exists('code', $changes)) {
                $changes['code'] = PromoCode::normalize((string) $changes['code']) ?: $promo->code;
            }
            if (array_key_exists('restrictions', $changes)) {
                $changes['restrictions'] = $this->restrictions($changes['restrictions']);
            }
            $before = $this->auditable($promo);
            $promo->fill($changes)->save();

            if ($promo->status === 'exhausted' && ! $promo->limitReached()) {
                $promo->transitionTo('active', $actor->id, 'Общий лимит увеличен');
            } elseif ($promo->status === 'active' && $promo->limitReached()) {
                $promo->transitionTo('exhausted', $actor->id, 'Достигнут общий лимит применений');
            }
            Audit::log('ADM-09', 'promo.updated', $promo, ['before' => $before, 'after' => $this->auditable($promo)], userId: $actor->id);

            return $promo->fresh();
        });
    }

    /** Draft → scheduled (period not started) or active (BR-PROMO-13: terms are published before the start). */
    public function publish(PromoCode $promo, User $actor): PromoCode
    {
        abort_unless($promo->status === 'draft', 422, 'Опубликовать можно только черновик.');
        abort_if($promo->valid_until && $promo->valid_until->isPast(), 422, 'Период действия уже закончился — измените даты.');
        $to = $promo->valid_from && $promo->valid_from->isFuture() ? 'scheduled' : 'active';
        $promo->transitionTo($to, $actor->id, 'Условия опубликованы', ['published_at' => now()]);
        Audit::log('ADM-09', 'promo.published', $promo, ['status' => $to], userId: $actor->id);

        return $promo->fresh();
    }

    public function deactivate(PromoCode $promo, string $reason, User $actor): PromoCode
    {
        abort_unless($promo->canTransition('deactivated'), 422, 'Этот промокод нельзя деактивировать в текущем статусе.');
        $promo->transitionTo('deactivated', $actor->id, $reason, ['deactivated_at' => now(), 'deactivation_reason' => $reason]);
        Audit::log('ADM-09', 'promo.deactivated', $promo, ['code' => $promo->code], $reason, $actor->id);

        return $promo->fresh();
    }

    /**
     * Batch of N unique individual codes with the same terms; codes are inserted in bulk with their initial state
     * written to the history.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBatch(array $data, User $actor): PromoBatch
    {
        return DB::transaction(function () use ($data, $actor) {
            $batch = PromoBatch::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'size' => (int) $data['size'],
                'prefix' => ! empty($data['prefix']) ? PromoCode::normalize($data['prefix']) : null,
                'created_by' => $actor->id,
            ]);
            $publish = ! empty($data['publish']);
            $validFrom = ! empty($data['valid_from']) ? CarbonImmutable::parse($data['valid_from'])->utc() : null;
            $status = ! $publish ? 'draft' : ($validFrom && $validFrom->isFuture() ? 'scheduled' : 'active');
            $now = now()->utc();
            $restrictions = $this->restrictions($data['restrictions'] ?? null);

            foreach (array_chunk($this->generator->many((int) $data['size'], $batch->prefix), 500) as $chunk) {
                $rows = [];
                $history = [];
                foreach ($chunk as $code) {
                    $id = (string) Str::uuid();
                    $rows[] = [
                        'id' => $id,
                        'code' => $code,
                        'title' => $data['title'],
                        'description' => $data['description'] ?? null,
                        'type' => $data['type'],
                        'value' => (int) $data['value'],
                        'kind' => 'individual',
                        'source' => 'batch',
                        'promo_batch_id' => $batch->id,
                        'valid_from' => $validFrom,
                        'valid_until' => ! empty($data['valid_until']) ? CarbonImmutable::parse($data['valid_until'])->utc() : null,
                        'total_limit' => $data['total_limit'] ?? 1,
                        'per_user_limit' => $data['per_user_limit'] ?? 1,
                        'min_amount' => $data['min_amount'] ?? null,
                        'restrictions' => $restrictions ? json_encode($restrictions) : null,
                        'status' => $status,
                        'published_at' => $publish ? $now : null,
                        'uses_count' => 0,
                        'created_by' => $actor->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $history[] = [
                        'id' => (string) Str::uuid(), 'model_type' => 'promo_code', 'model_id' => $id, 'field' => 'status',
                        'from' => null, 'to' => $status, 'event' => 'promo.code.'.$status, 'actor_id' => $actor->id,
                        'reason' => 'Пакет «'.$data['title'].'»', 'context' => null, 'created_at' => $now,
                    ];
                }
                DB::table('promo_codes')->insert($rows);
                DB::table('state_transitions')->insert($history);
            }

            Outbox::record('promo.batch.created', $batch, ['size' => (int) $data['size'], 'status' => $status], $actor->id);
            Audit::log('ADM-09', 'promo.batch_created', $batch, ['size' => (int) $data['size'], 'type' => $data['type'], 'value' => (int) $data['value'], 'status' => $status], userId: $actor->id);

            return $batch;
        });
    }

    public function publishBatch(PromoBatch $batch, User $actor): int
    {
        $count = 0;
        DB::transaction(function () use ($batch, $actor, &$count) {
            foreach (PromoCode::where('promo_batch_id', $batch->id)->where('status', 'draft')->lockForUpdate()->get() as $promo) {
                if ($promo->valid_until && $promo->valid_until->isPast()) {
                    continue;
                }
                $promo->transitionTo($promo->valid_from && $promo->valid_from->isFuture() ? 'scheduled' : 'active', $actor->id, 'Условия опубликованы', ['published_at' => now()]);
                $count++;
            }
            Audit::log('ADM-09', 'promo.batch_published', $batch, ['codes' => $count], userId: $actor->id);
        });

        return $count;
    }

    public function deactivateBatch(PromoBatch $batch, string $reason, User $actor): int
    {
        $count = 0;
        DB::transaction(function () use ($batch, $reason, $actor, &$count) {
            foreach (PromoCode::where('promo_batch_id', $batch->id)->whereIn('status', ['draft', 'scheduled', 'active'])->lockForUpdate()->get() as $promo) {
                $promo->transitionTo('deactivated', $actor->id, $reason, ['deactivated_at' => now(), 'deactivation_reason' => $reason]);
                $count++;
            }
            Audit::log('ADM-09', 'promo.batch_deactivated', $batch, ['codes' => $count], $reason, $actor->id);
        });

        return $count;
    }

    /**
     * BR-PROMO-10: uses (applied), discount sum, conversion = uses that ended in a paid session / all reservations.
     *
     * @param  Collection<int, string>|list<string>  $codeIds
     * @return array<string, array{reserved_total: int, pending: int, applied: int, restored: int, discount_sum: int, conversion: float|null}>
     */
    public function statsFor(Collection|array $codeIds): array
    {
        $ids = collect($codeIds)->values();
        $out = $ids->mapWithKeys(fn ($id) => [$id => self::emptyStats()])->all();
        if ($ids->isEmpty()) {
            return $out;
        }
        $rows = PromoRedemption::whereIn('promo_code_id', $ids)
            ->selectRaw('promo_code_id, status, count(*) as cnt, coalesce(sum(discount_amount), 0) as total')
            ->groupBy('promo_code_id', 'status')
            ->get();
        foreach ($rows as $row) {
            $s = &$out[$row->promo_code_id];
            $s['reserved_total'] += (int) $row->cnt;
            if ($row->status === 'reserved') {
                $s['pending'] += (int) $row->cnt;
            } elseif ($row->status === 'applied') {
                $s['applied'] += (int) $row->cnt;
                $s['discount_sum'] += (int) $row->total;
            } elseif ($row->status === 'restored') {
                $s['restored'] += (int) $row->cnt;
            }
            unset($s);
        }
        foreach ($out as $id => $s) {
            $out[$id]['conversion'] = $s['reserved_total'] > 0 ? round($s['applied'] * 100 / $s['reserved_total'], 1) : null;
        }

        return $out;
    }

    /** Aggregated statistics for ADM-09: totals, by type, by source, referral program. */
    public function overview(): array
    {
        $byStatus = PromoCode::selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->map(fn ($v) => (int) $v)->all();
        $redemptions = PromoRedemption::selectRaw('status, count(*) as cnt, coalesce(sum(discount_amount), 0) as total')->groupBy('status')->get()->keyBy('status');
        $reservedTotal = (int) $redemptions->sum('cnt');
        $applied = (int) ($redemptions->get('applied')?->cnt ?? 0);
        $byType = DB::table('promo_redemptions')
            ->join('promo_codes', 'promo_codes.id', '=', 'promo_redemptions.promo_code_id')
            ->where('promo_redemptions.status', 'applied')
            ->selectRaw('promo_codes.type as type, count(*) as cnt, coalesce(sum(promo_redemptions.discount_amount), 0) as total')
            ->groupBy('promo_codes.type')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->type => ['applied' => (int) $r->cnt, 'discount_sum' => (int) $r->total]])
            ->all();

        return [
            'codes_by_status' => $byStatus,
            'reserved_total' => $reservedTotal,
            'applied' => $applied,
            'pending' => (int) ($redemptions->get('reserved')?->cnt ?? 0),
            'restored' => (int) ($redemptions->get('restored')?->cnt ?? 0),
            'discount_sum' => (int) ($redemptions->get('applied')?->total ?? 0),
            'conversion' => $reservedTotal > 0 ? round($applied * 100 / $reservedTotal, 1) : null,
            'by_type' => $byType,
            'referral' => ReferralInvite::selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /** @return array{reserved_total: int, pending: int, applied: int, restored: int, discount_sum: int, conversion: float|null} */
    public static function emptyStats(): array
    {
        return ['reserved_total' => 0, 'pending' => 0, 'applied' => 0, 'restored' => 0, 'discount_sum' => 0, 'conversion' => null];
    }

    /**
     * Dates arrive as ISO strings with an offset ("2026-10-10T00:00:00+03:00"); they are converted to UTC instances
     * so the stored moment is exact (rule 1 of sequences_states.md).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function utcDates(array $data): array
    {
        foreach (['valid_from', 'valid_until'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                $data[$key] = CarbonImmutable::parse($data[$key])->utc();
            }
        }

        return $data;
    }

    /** Keep only known restriction keys with non-empty values. */
    public function restrictions(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }
        $out = [];
        foreach (['service_types', 'psychologist_ids', 'price_category_ids'] as $key) {
            if (! empty($input[$key]) && is_array($input[$key])) {
                $out[$key] = array_values(array_unique($input[$key]));
            }
        }
        $segment = array_filter([
            'new_clients' => ! empty($input['segment']['new_clients']) ? true : null,
            'registered_after' => $input['segment']['registered_after'] ?? null,
            'min_held_sessions' => ! empty($input['segment']['min_held_sessions']) ? (int) $input['segment']['min_held_sessions'] : null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($segment) {
            $out['segment'] = $segment;
        }

        return $out ?: null;
    }

    private function auditable(PromoCode $promo): array
    {
        return Arr::only($promo->attributesToArray(), ['code', 'title', 'type', 'value', 'kind', 'owner_user_id', 'valid_from', 'valid_until', 'total_limit', 'per_user_limit', 'min_amount', 'restrictions', 'status']);
    }
}
