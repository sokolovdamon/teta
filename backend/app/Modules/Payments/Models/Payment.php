<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** ST-03: operation through the payment adapter or emulator. Events: pay.payment.{status}. */
class Payment extends Model
{
    use HasStateMachine, HasUuids;

    protected static string $eventPrefix = 'pay.payment';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'with_payer' => 'boolean'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'created' => ['requires_3ds', 'succeeded', 'declined', 'unknown'],
            'requires_3ds' => ['succeeded', 'declined'],
            'unknown' => ['succeeded', 'declined'],
            'succeeded' => ['partially_refunded', 'refunded'],
            'partially_refunded' => ['partially_refunded', 'refunded'],
            'declined' => [],
            'refunded' => [],
        ]];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function refundable(): int
    {
        return max(0, $this->amount - $this->refunded_amount);
    }
}
