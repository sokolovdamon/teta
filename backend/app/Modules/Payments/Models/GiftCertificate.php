<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
        return ['valid_until' => 'datetime', 'activated_at' => 'datetime'];
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
}
