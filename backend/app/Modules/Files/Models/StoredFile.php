<?php

namespace App\Modules\Files\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class StoredFile extends Model
{
    use HasUuids, SoftDeletes, UtcDates;

    protected $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Public files are served directly; private ones via a short-lived signed link (X-09). */
    public function url(int $minutes = 30): string
    {
        if ($this->visibility === 'public') {
            return Storage::disk($this->disk)->url($this->path);
        }

        return URL::temporarySignedRoute('files.download', now()->addMinutes($minutes), ['file' => $this->id]);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'purpose' => $this->purpose,
            'url' => $this->url(),
        ];
    }
}
