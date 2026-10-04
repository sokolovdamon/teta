<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ST-18 (DEC-43, DM-14): a prepayment, not a discount. Entering the code credits the whole nominal to the
 * client balance as certificate funds (spendable, never withdrawn to a card); the psychologist's share is not reduced.
 */
class GiftCertificate extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    protected static string $eventPrefix = 'pay.certificate';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['valid_until' => 'datetime', 'activated_at' => 'datetime', 'sent_at' => 'datetime', 'expired_at' => 'datetime'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'awaiting_payment' => ['paid', 'unpaid'],
            'paid' => ['activated', 'expired'],
            'unpaid' => [],
            'activated' => [],
            'expired' => [],
        ]];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Normalised form of a code typed by a person: upper case, letters and numbers only. */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($code))) ?? '';
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', self::normalizeCode($code));
    }
}
