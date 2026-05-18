<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Services\TeamLeadershipService;
use Illuminate\Auth\Access\HandlesAuthorization;

class ConversationPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->role?->name === 'admin') {
            return true;
        }

        return null;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->users()->where('users.id', $user->id)->exists();
    }

    public function moderate(User $user, Conversation $conversation): bool
    {
        if ($user->role?->name === 'admin') {
            return true;
        }

        if ($conversation->agent_user_id === null) {
            return false;
        }

        $ledIds = app(TeamLeadershipService::class)->getLedTeamMemberUserIds($user->id);

        return !empty($ledIds) && in_array($conversation->agent_user_id, $ledIds, true);
    }

    public function close(User $user, Conversation $conversation): bool
    {
        return $conversation->agent_user_id === $user->id;
    }

    public function reopen(User $user, Conversation $conversation): bool
    {
        return $conversation->agent_user_id === $user->id;
    }
}
