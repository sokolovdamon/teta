<?php

namespace App\Modules\Auth\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'pending_email' => $this->pending_email,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'roles' => $this->roleCodes(),
            'permissions' => $this->isSuperAdmin() ? ['*'] : $this->permissionCodes(),
            'email_verified' => $this->email_verified_at !== null,
            'psychologist_id' => $this->psychologist?->id,
            'company_id' => DB::table('company_user')->where('user_id', $this->id)->value('company_id'),
            'deletion_requested_at' => $this->deletion_requested_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
