<?php

namespace App\Policies;

use App\Models\BuyerForm;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BuyerFormPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return true;
    }

    public function view(User $user, BuyerForm $buyerForm)
    {
        return true;
    }

    public function create(User $user)
    {
        return in_array($user->role?->name, ['agent', 'admin']);
    }

    public function update(User $user, BuyerForm $buyerForm)
    {
        return $user->role?->name === 'admin'
            || ($buyerForm->agent && $buyerForm->agent->user_id === $user->id);
    }

    public function delete(User $user, BuyerForm $buyerForm)
    {
        return $user->role?->name === 'admin'
            || ($buyerForm->agent && $buyerForm->agent->user_id === $user->id);
    }

    public function viewRegistrations(User $user, BuyerForm $buyerForm)
    {
        return $user->role?->name === 'admin'
            || ($buyerForm->agent && $buyerForm->agent->user_id === $user->id);
    }
}
