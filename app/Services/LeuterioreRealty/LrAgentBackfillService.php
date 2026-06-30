<?php

namespace App\Services\LeuterioreRealty;

use App\Models\Agent;
use App\Models\User;
use App\Support\OfficeRegionMap;

/**
 * On login, fill any blank LR-sourced fields on the user's agent profile from
 * Leuterio Realty: lr_email, birthdate, gender, and region/lr_state. Only fills
 * blanks (never overwrites), and only hits the LR API when something is actually
 * missing — so once a profile is complete, login does no extra work.
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
        // Re-fetch region only when BOTH region and the raw state are blank. Once
        // lr_state is recorded (even an unmappable one), we stop hitting LR — an
        // agent whose state doesn't map to an office region won't re-fetch v1 on
        // every login.
        $needsRegion  = empty($agent->region) && empty($agent->lr_state);
        if (!$needsLrEmail && !$needsBirth && !$needsGender && !$needsRegion) {
            return;
        }

        $email   = $user->email;
        $updates = [];

        // region / lr_state come from the v1 agent endpoint (the `state` field is
        // NOT in the v2 detail payload used for birthday/gender below).
        if ($needsRegion) {
            $v1 = $this->lr->fetchAgentByEmail($email);
            $state = trim((string) ($v1['state'] ?? ''));
            if ($state !== '') {
                $updates['lr_state'] = $state;
                $region = OfficeRegionMap::regionOf($state);
                if ($region !== null) {
                    $updates['region'] = $region;
                }
            }
        }

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
