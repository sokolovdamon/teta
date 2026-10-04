<?php

namespace App\Modules\Corporate\Models;

use App\Modules\Consent\Models\LegalDocumentVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateProgram extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['email_domains' => 'array', 'allowed_formats' => 'array', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(CorporateParticipation::class);
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'terms_version_id');
    }
}
