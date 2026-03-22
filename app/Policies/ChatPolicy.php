<?php

namespace App\Policies;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->role?->name === 'admin') {
            return true;
        }

        return null;
    }

    public function view(User $user, Chat $chat): bool
    {
        if ($chat->user_id === $user->id) {
            return true;
        }

        return $chat->conversations()
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }
}
