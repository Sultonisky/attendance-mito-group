<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * Access is per user: only direct permissions authorize. A role is a label
 * plus a permission template — attaching a role copies its template onto the
 * user (SUPER_ADMIN: every permission), after which each user's grants are
 * edited independently. Role permissions never grant access by themselves.
 */
#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    use HasRoles {
        assignRole as protected attachRoles;
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Attach roles and copy the template of each newly attached role.
     * Re-assigning a role the user already has changes nothing.
     */
    public function assignRole(...$roles): static
    {
        if (! $this->exists) {
            return $this->attachRoles(...$roles);
        }

        $before = $this->roles()->get()->modelKeys();

        $this->attachRoles(...$roles);

        $attached = $this->roles()->with('permissions')->get()
            ->reject(fn ($role) => in_array($role->getKey(), $before, true));

        $template = $attached->contains('name', 'SUPER_ADMIN')
            ? Permission::all()
            : $attached->flatMap(fn ($role) => $role->permissions);

        if ($template->isNotEmpty()) {
            $this->givePermissionTo($template->unique('id')->values());
        }

        return $this;
    }

    protected function hasPermissionViaRole(PermissionContract $permission): bool
    {
        return false;
    }

    public function getPermissionsViaRoles(): Collection
    {
        return collect();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
