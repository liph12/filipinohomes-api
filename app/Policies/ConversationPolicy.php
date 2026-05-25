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
        return $this->canModerateForAgent($user, $conversation);
    }

    public function close(User $user, Conversation $conversation): bool
    {
        return $this->canModerateForAgent($user, $conversation);
    }

    public function reopen(User $user, Conversation $conversation): bool
    {
        return $this->canModerateForAgent($user, $conversation);
    }

    /**
     * Shared predicate for moderate / close / reopen: the assigned agent
     * themselves, OR a team leader of that agent. Admins bypass via
     * before() so they're never tested here.
     */
    private function canModerateForAgent(User $user, Conversation $conversation): bool
    {
        if ($conversation->agent_user_id === null) {
            return false;
        }

        if ($conversation->agent_user_id === $user->id) {
            return true;
        }

        $ledIds = app(TeamLeadershipService::class)->getLedTeamMemberUserIds($user->id);

        return !empty($ledIds) && in_array($conversation->agent_user_id, $ledIds, true);
    }
}
