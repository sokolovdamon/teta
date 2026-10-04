<?php

namespace App\Modules\Payouts\Models;

use App\Models\User;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ST-07: weekly payout to one psychologist or supervisor. The gateway idempotency key is the payout id,
 * so a repeated send never creates a second payout.
 */
class Payout extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    public const STATUS_LABELS = [
        'checking' => 'Проверка условий',
        'blocked_supervision' => 'Заблокирована: нет супервизии месяца',
        'deferred' => 'Отложена',
        'in_registry' => 'В реестре',
        'excluded' => 'Исключена администратором',
        'sent' => 'Отправлена',
        'unknown' => 'Статус уточняется',
        'paid' => 'Выполнена',
        'rejected' => 'Отклонена',
    ];

    /** Lines that went to the registry (money is or was on its way). */
    public const REGISTRY_STATUSES = ['in_registry', 'sent', 'unknown', 'paid', 'rejected', 'excluded'];

    protected static string $eventPrefix = 'payout';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'paid_at' => 'datetime', 'status_checked_at' => 'datetime'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'checking' => ['blocked_supervision', 'deferred', 'in_registry'],
            'in_registry' => ['excluded', 'sent'],
            'sent' => ['paid', 'rejected', 'unknown'],
            'unknown' => ['paid', 'rejected'],
            'blocked_supervision' => [],
            'deferred' => [],
            'excluded' => [],
            'paid' => [],
            'rejected' => [],
        ]];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(PayoutRegistry::class, 'payout_registry_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'registry_id' => $this->payout_registry_id,
            'amount' => (int) $this->amount,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'reason' => $this->reason,
            'card_mask' => $this->relationLoaded('paymentMethod') ? $this->paymentMethod?->card_mask : null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
