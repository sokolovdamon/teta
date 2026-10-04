<?php

namespace App\Modules\Dictionaries\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientRequest extends Model
{
    use HasUuids;

    protected $table = 'client_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RequestGroup::class, 'request_group_id');
    }

    /** Landing page path: /help/{slug} or /help/para/{slug} (DEC-11). */
    public function landingPath(): string
    {
        return $this->format === 'pair' ? "/help/para/{$this->slug}" : "/help/{$this->slug}";
    }
}
