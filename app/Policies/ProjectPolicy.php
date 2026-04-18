<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        $role = $user->role->name ?? null;

        return $role === 'admin' || $role === 'agent';
    }

    public function update(User $user, Project $project): bool
    {
        return ($user->role->name ?? null) === 'admin';
    }

    public function link(User $user, Project $project): bool
    {
        return ($user->role->name ?? null) === 'admin';
    }

    public function delete(User $user, Project $project): bool
    {
        return ($user->role->name ?? null) === 'admin';
    }

}
