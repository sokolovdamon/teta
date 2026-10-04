<?php

namespace App\Modules\Corporate\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function programs(): HasMany
    {
        return $this->hasMany(CorporateProgram::class);
    }

    public function hrUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('created_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CorporateInvoice::class);
    }
}
