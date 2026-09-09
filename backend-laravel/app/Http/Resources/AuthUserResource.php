<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe authentication profile of a user.
 *
 * Only exposes the authorization context the SPA needs for UX. Never
 * includes passwords, tokens, session identifiers, or biometric data.
 *
 * @mixin User
 */
class AuthUserResource extends JsonResource
{
    /**
     * Transform the resource into a JSON array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }
}
