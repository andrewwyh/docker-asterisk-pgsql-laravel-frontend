<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin || $tenant->members()->whereKey($user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin || $tenant->members()->whereKey($user->id)->wherePivot('role', 'admin')->exists();
    }

    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin;
    }
}
