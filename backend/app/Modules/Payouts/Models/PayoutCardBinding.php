<?php

namespace App\Modules\Payouts\Models;

use App\Models\User;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PRO-09: binding of the self-employed card for payouts through the gateway (pending → succeeded | declined). */
class PayoutCardBinding extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
