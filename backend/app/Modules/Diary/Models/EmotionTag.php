<?php

namespace App\Modules\Diary\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Emotion tag for diary entries (DEC-41): fixed list edited by admins in ADM-13. */
class EmotionTag extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('title');
    }

    /** @return array{id: string, code: string, title: string} */
    public function toApi(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'title' => $this->title];
    }
}
