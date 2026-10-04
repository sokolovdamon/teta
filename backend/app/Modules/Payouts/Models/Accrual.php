<?php

namespace App\Modules\Payouts\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ST-06: accrual to a psychologist or supervisor, 70 % of the full fixed price (DEC-57).
 *
 * Ledger rules: net() = amount − reversed_amount. Available balance is the sum of net() over "accrued",
 * money in payout — over "in_registry". A reversal of an unpaid accrual raises reversed_amount ("accrued" → "accrued"
 * or "reversed"); once the money is committed to a payout (in registry, paid) the reversal becomes a negative
 * correction accrual (kind "correction") that is netted in the next payouts (BR-PAYOUT-12).
 */
class Accrual extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    public const KIND_SESSION = 'session';

    public const KIND_CLIENT_NO_SHOW = 'client_no_show';

    public const KIND_LATE_CANCEL = 'late_cancel';

    public const KIND_SUPERVISION = 'supervision';

    public const KIND_CORRECTION = 'correction';

    /** Kinds earned by a therapy session outcome (one live accrual per session). */
    public const SESSION_KINDS = [self::KIND_SESSION, self::KIND_CLIENT_NO_SHOW, self::KIND_LATE_CANCEL];

    public const KIND_LABELS = [
        self::KIND_SESSION => 'Проведённая сессия',
        self::KIND_CLIENT_NO_SHOW => 'Неявка клиента',
        self::KIND_LATE_CANCEL => 'Отмена клиентом после списания',
        self::KIND_SUPERVISION => 'Супервизия',
        self::KIND_CORRECTION => 'Корректировка',
    ];

    public const STATUS_LABELS = [
        'accrued' => 'Начислено',
        'in_registry' => 'В реестре выплат',
        'paid' => 'Выплачено',
        'reversed' => 'Сторнировано',
        'corrected' => 'Скорректировано после выплаты',
    ];

    protected static string $eventPrefix = 'payout.accrual';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'adjustments' => 'array', 'meta' => 'array'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'accrued' => ['accrued', 'reversed', 'in_registry'],
            'in_registry' => ['accrued', 'paid'],
            'paid' => ['corrected'],
            'reversed' => [],
            'corrected' => [],
        ]];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'correction_of_id');
    }

    public function net(): int
    {
        return (int) $this->amount - (int) $this->reversed_amount;
    }

    /** Adjustments are keyed (e.g. "complaint:{id}") so a repeated event never applies twice. */
    public function hasAdjustment(string $key): bool
    {
        return collect($this->adjustments ?? [])->contains(fn ($a) => ($a['key'] ?? null) === $key);
    }
}
