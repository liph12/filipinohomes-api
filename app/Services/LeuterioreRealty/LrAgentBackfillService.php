<?php

namespace App\Services\LeuterioreRealty;

use App\Models\Agent;
use App\Models\User;

/**
 * On login, fill any blank LR-sourced fields on the user's agent profile from
 * Leuterio Realty: lr_email, birthdate, gender. Only fills blanks (never
 * overwrites), and only hits the LR API when something is actually missing —
 * so once a profile is complete, login does no extra work.
 */
class LrAgentBackfillService
{
    public function __construct(private LrApiService $lr)
    {
    }

    public function backfill(User $user): void
    {
        $agent = $user->agent;
        if (!$agent) {
            return;
        }

        $needsLrEmail = empty($agent->lr_email);
        $needsBirth   = empty($agent->birthdate);
        $needsGender  = empty($agent->gender);
        if (!$needsLrEmail && !$needsBirth && !$needsGender) {
            return;
        }

        $email   = $user->email;
        $updates = [];

        // Link the login email as lr_email unless it's already linked to a
        // different agent (lr_email is unique).
        if ($needsLrEmail) {
            $takenElsewhere = Agent::where('lr_email', $email)
                ->where('id', '!=', $agent->id)
                ->exists();
            if (!$takenElsewhere) {
                $updates['lr_email'] = $email;
            }
        }

        // birthday / gender come from the v2 detail endpoint.
        if ($needsBirth || $needsGender) {
            $detail = $this->lr->agentDetail($email);
            if ($detail) {
                if ($needsBirth) {
                    $birthday = trim((string) ($detail['birthday'] ?? ''));
                    if ($birthday !== '') {
                        $updates['birthdate'] = $birthday;
                    }
                }
                if ($needsGender) {
                    $gender = match ($detail['gender'] ?? null) {
                        0, '0' => 'male',
                        1, '1' => 'female',
                        default => null,
                    };
                    if ($gender !== null) {
                        $updates['gender'] = $gender;
                    }
                }
            }
        }

        if ($updates) {
            $agent->fill($updates)->saveQuietly();
        }
    }
}
