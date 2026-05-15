<?php

namespace App\Policies;

use App\Models\User;

class AiUsagePolicy
{
    /**
     * Admins bypass the daily AI usage limits on create-listing endpoints
     * (analyze-title, generate-description-tags, classify-photos).
     */
    public function bypassDailyLimit(?User $user): bool
    {
        return $user && $user->role->name === 'admin';
    }
}
