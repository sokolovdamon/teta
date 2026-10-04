<?php

namespace App\Modules\Psychologists\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Requirement of one paid supervision per calendar month (MSK) is met (or exempt) for a psychologist. */
class SupervisionMonthRequirement extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'met_at' => 'datetime'];
    }

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
