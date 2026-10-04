<?php

namespace App\Modules\Corporate\Models;

use App\Models\User;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ST-19 (DEC-25, DEC-53): the company receives the employee's email and number of sessions only after the
 * employee accepts the program terms; without that access is not activated.
 */
class CorporateParticipation extends Model
{
    use HasStateMachine, HasUuids;

    protected static string $eventPrefix = 'b2b.participation';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'activated_at' => 'datetime', 'disconnected_at' => 'datetime', 'email_verified_at' => 'datetime'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'invited' => ['email_pending', 'disconnected'],
            'email_pending' => ['email_pending', 'terms_pending', 'disconnected'],
            'terms_pending' => ['active', 'disconnected'],
            'active' => ['limit_exhausted', 'disconnected', 'program_ended'],
            'limit_exhausted' => ['active', 'disconnected', 'program_ended'],
            'disconnected' => [],
            'program_ended' => [],
        ]];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(CorporateProgram::class, 'corporate_program_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
