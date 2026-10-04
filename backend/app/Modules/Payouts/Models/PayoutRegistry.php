<?php

namespace App\Modules\Payouts\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Weekly payout registry (SEQ-08): draft (waits for approval in ADM-08) → approved → sent → completed.
 * Every payout line built in the run is attached, including blocked and deferred ones, so ADM-08 shows the reasons.
 */
class PayoutRegistry extends Model
{
    use HasUuids, UtcDates;

    public const STATUS_LABELS = [
        'draft' => 'Ждёт утверждения',
        'approved' => 'Утверждён',
        'sent' => 'Отправлен',
        'completed' => 'Завершён',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'auto_approve' => 'boolean',
        ];
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }
}
