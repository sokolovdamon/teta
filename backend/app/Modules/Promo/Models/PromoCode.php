<?php

namespace App\Modules\Promo\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ST-17 (Э8). Types: percent, fixed, first_session. The discount reduces only the platform share:
 * the psychologist always gets 70 % of the full price (DEC-57).
 *
 * restrictions: {service_types: [individual|pair], psychologist_ids: [...], price_category_ids: [...],
 *                segment: {new_clients: bool, registered_after: Y-m-d, min_held_sessions: int}}
 */
class PromoCode extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    public const TYPE_LABELS = [
        'percent' => 'Процентная скидка',
        'fixed' => 'Фиксированная скидка',
        'first_session' => 'Льготная первая сессия',
    ];

    public const KIND_LABELS = ['mass' => 'Массовый', 'individual' => 'Индивидуальный'];

    public const SOURCE_LABELS = ['admin' => 'Создан администратором', 'batch' => 'Пакет', 'referral' => 'Пригласи друга', 'compensation' => 'Компенсация'];

    public const STATUS_LABELS = [
        'draft' => 'Черновик',
        'scheduled' => 'Запланирован',
        'active' => 'Активен',
        'exhausted' => 'Лимит исчерпан',
        'expired' => 'Истёк',
        'deactivated' => 'Деактивирован',
    ];

    protected static string $eventPrefix = 'promo.code';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'published_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'restrictions' => 'array',
        ];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'draft' => ['scheduled', 'active', 'deactivated'],
            'scheduled' => ['active', 'deactivated'],
            'active' => ['active', 'exhausted', 'expired', 'deactivated'],
            'exhausted' => ['active', 'expired'],
            'expired' => [],
            'deactivated' => [],
        ]];
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PromoBatch::class, 'promo_batch_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id')->withTrashed();
    }

    /** Discount in kopecks for the given price. */
    public function discountFor(int $price): int
    {
        $discount = match ($this->type) {
            'fixed' => (int) $this->value,
            default => intdiv($price * (int) $this->value, 100),
        };

        return max(0, min($price, $discount));
    }

    public function limitReached(): bool
    {
        return $this->total_limit !== null && (int) $this->uses_count >= (int) $this->total_limit;
    }

    /** Short human description of the discount: "−20 %", "−500 ₽", "первая сессия −50 %". */
    public function discountLabel(): string
    {
        return match ($this->type) {
            'fixed' => '−'.number_format(intdiv((int) $this->value, 100), 0, ',', ' ').' ₽',
            'first_session' => 'первая сессия −'.$this->value.' %',
            default => '−'.$this->value.' %',
        };
    }
}
