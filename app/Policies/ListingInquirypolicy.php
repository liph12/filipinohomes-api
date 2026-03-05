<?php

namespace App\Policies;

use App\Models\ListingInquiry;
use App\Models\User;

class ListingInquiryPolicy
{
    /**
     * Admins can do everything.
     */
    public function before(User $user): ?bool
    {
        if ($user->role->name === 'admin') {
            return true;
        }
        return null;
    }

    /**
     * Client who owns it or agent assigned to it can view.
     */
    public function view(User $user, ListingInquiry $inquiry): bool
    {
        return $user->id === $inquiry->client_id
            || ($user->agent && $user->agent->id === $inquiry->agent_id);
    }

    /**
     * Only the assigned agent can update status.
     */
    public function update(User $user, ListingInquiry $inquiry): bool
    {
        return $user->agent && $user->agent->id === $inquiry->agent_id;
    }

    /**
     * Only admin can delete (handled by before()).
     */
    public function delete(User $user, ListingInquiry $inquiry): bool
    {
        return false;
    }
}