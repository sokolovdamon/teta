<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Card binding with the payer present (3-D Secure in the provider's or emulator's form): pending → succeeded | declined. */
class CardBinding extends Model
{
    use HasUuids, UtcDates;

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const DECLINED = 'declined';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'confirmation_url' => $this->status === self::PENDING ? $this->confirmation_url : null,
            'return_path' => $this->return_path,
            'error_code' => $this->error_code,
            'card' => $this->payment_method_id ? $this->method?->toApi() : null,
        ];
    }
}
