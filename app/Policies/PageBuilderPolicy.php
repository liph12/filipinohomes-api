<?php

namespace App\Policies;

use App\Models\PageBuilder;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PageBuilderPolicy
{
    use HandlesAuthorization;

    public function viewAny(?User $user)
    {
        return true;
    }

    public function view(?User $user, PageBuilder $pageBuilder)
    {
        return true;
    }

    public function create(User $user)
    {
        return $user->role->name === 'agent' || $user->role->name === 'admin';
    }

    public function update(User $user, PageBuilder $pageBuilder)
    {
        return $user->role->name === 'admin' || 
            ($user->role->name === 'agent' && $pageBuilder->agent->user_id === $user->id);
    }

    public function delete(User $user, PageBuilder $pageBuilder)
    {
        return $user->role->name === 'admin';
    }
}