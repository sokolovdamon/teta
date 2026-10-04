<?php

namespace App\Modules\Consent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LegalDocument extends Model
{
    use HasUuids;

    public const PERSONAL_DATA = 'personal_data';

    public const OFFER = 'offer';

    public const PRIVACY_POLICY = 'privacy_policy';

    public const TERMS = 'terms';

    public const REVIEW_PUBLICATION = 'review_publication';

    public const MAILING = 'mailing';

    public const COOKIES = 'cookies';

    public const CORPORATE_TERMS = 'corporate_terms';

    protected $fillable = ['slug', 'title', 'kind', 'requires_consent', 'is_public'];

    protected function casts(): array
    {
        return ['requires_consent' => 'boolean', 'is_public' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LegalDocumentVersion::class)->orderByDesc('published_at');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(LegalDocumentVersion::class)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->latest('published_at');
    }

    public static function currentVersionOfKind(string $kind): ?LegalDocumentVersion
    {
        return static::where('kind', $kind)->first()?->currentVersion;
    }
}
