<?php

namespace App\Modules\Diary\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** CL-01: the day the client last skipped the mood check-in (in the client's time zone). Keyed by the client id. */
class DiaryPromptState extends Model
{
    use HasUuids, UtcDates;

    protected $primaryKey = 'client_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dismissed_on' => 'date:Y-m-d'];
    }
}
