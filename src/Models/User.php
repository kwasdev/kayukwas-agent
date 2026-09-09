<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Model
{
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'is_active',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function userRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function isSuperuser(): bool
    {
        return $this->userRoles->contains(fn ($role) => $role->name === 'superuser');
    }

    public function hasPermission(string $permissionName): bool
    {
        if ($this->isSuperuser()) {
            return true;
        }

        foreach ($this->userRoles as $role) {
            if ($role->permissions->contains('name', $permissionName)) {
                return true;
            }
        }

        return false;
    }
}
