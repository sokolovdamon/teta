<?php

namespace App\Modules\Corporate\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Monthly invoice and act; receipt CREDIT_PAYMENT when the company pays (DEC-22). */
class CorporateInvoice extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'issued_at' => 'datetime', 'paid_at' => 'datetime', 'act_issued_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
