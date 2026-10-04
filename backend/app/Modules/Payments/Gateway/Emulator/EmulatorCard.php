<?php

namespace App\Modules\Payments\Gateway\Emulator;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A card token issued by the emulator after a binding or a payment with "save card". No card numbers are stored. */
class EmulatorCard extends Model
{
    use HasUuids, UtcDates;

    protected $table = 'emulator_cards';

    protected $guarded = ['id'];
}
