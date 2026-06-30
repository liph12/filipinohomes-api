<?php

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ListingPolicy
{
    use HandlesAuthorization;

    public function viewAny(?User $user)
    {
        return true; // Everyone can see listings
    }

    // Check if user can view a specific listing
    public function view(?User $user, Listing $listing)
    {
        if (!$user) {
            // Guest users can only see public listings
            return $listing->visibility === 'public';
        }

        // Admin can see everything
        if ($user->role->name === 'admin') {
            return true;
        }

        // Agents can see their own listings or public listings
        if ($user->role->name === 'agent') {
             return true;
        }

        // Secretary (read + verify) can view listings whose agent is in their
        // office region. Edit/delete stay admin-only — update()/delete() below
        // deliberately have no secretary branch.
        if ($user->isSecretary()) {
            $region = $user->secretaryRegion();
            return $region !== null && optional($listing->agent)->region === $region;
        }

        // Default deny
        return false;
    }

    public function create(User $user)
    {
        return $user->role->name === 'agent' || $user->role->name === 'admin';
    }

    public function update(User $user, Listing $listing)
    {
        return $user->role->name === 'admin' || ($user->role->name === 'agent' && $listing->agent->user_id === $user->id);
    }

    public function delete(User $user, Listing $listing)
    {
        return $user->role->name === 'admin' || ($user->role->name === 'agent' && $listing->agent->user_id === $user->id);
    }
}