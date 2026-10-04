<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** 54-ФЗ receipt: PREPAYMENT_FULL (B2C autocharge) or CREDIT_PAYMENT (B2B), DEC-22. */
class Receipt extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'fiscal_data' => 'array'];
    }
}
