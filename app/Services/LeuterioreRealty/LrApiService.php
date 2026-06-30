<?php

namespace App\Services\LeuterioreRealty;

use Illuminate\Support\Facades\Http;

class LrApiService
{
    private const BASE_URL = 'https://api.leuteriorealty.com/lr/v1/public/api/agent';
    // v2 endpoint exposes richer agent detail (birthday, gender).
    private const AGENT_DETAIL_URL = 'https://api.leuteriorealty.com/lr/v2/public/api/agents';

    // LR roleId → Filipino Homes role_id
    // LR 1 = admin → FH 1 (admin)
    // LR 3 = secretary → FH 5 (secretary) - bypass FIRE check (secretary is not a licensed agent, staff role)
    // LR 4 = agent → FH 2 (agent) — requires FIRE check
    // LR 6 = team leader → FH 2 (agent) — bypasses FIRE check
    // LR 7 = unit manager → FH 2 (agent) — bypasses FIRE check
    private const ALLOWED_LR_ROLES = [1, 3, 4, 6, 7];
    private const FIRE_CHECK_REQUIRED_ROLES = [4];

    private const LR_ROLE_TO_FH_ROLE = [
        1 => 1, // admin
        3 => 5, // secretary
        4 => 2, // agent
        6 => 2, // agent (team leader)
        7 => 2, // agent (unit manager)
    ];

    public function fetchAgentByEmail(string $email): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::BASE_URL . '/' . urlencode($email));

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Richer agent detail from the v2 endpoint — includes `birthday` and
     * `gender` (0 = male, 1 = female). Returns the `data` payload or null.
     */
    public function agentDetail(string $email): ?array
    {
        try {
            // The v2 detail payload is large and routinely takes ~11s, so the
            // usual 10s timeout silently fails. Give it generous headroom.
            $res = Http::timeout(30)->acceptJson()
                ->get(self::AGENT_DETAIL_URL . '/' . urlencode(strtolower(trim($email))));
            return $res->successful() ? $res->json('data') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isAllowedRole(array $lrData): bool
    {
        return in_array($lrData['roleId'] ?? null, self::ALLOWED_LR_ROLES, true);
    }

    public function requiresFireCheck(array $lrData): bool
    {
        return in_array($lrData['roleId'] ?? null, self::FIRE_CHECK_REQUIRED_ROLES, true);
    }

    public function hasRequiredFireCertificates(array $lrData): bool
    {
        return ($lrData['fire_certificates'] ?? 0) >= 3;
    }

    public function mapToFhRoleId(array $lrData): int
    {
        return self::LR_ROLE_TO_FH_ROLE[$lrData['roleId']] ?? 2;
    }

    public function parseName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName));

        if (count($parts) === 1) {
            return [
                'first_name' => $parts[0],
                'middle_name' => null,
                'last_name' => null,
            ];
        }

        if (count($parts) === 2) {
            return [
                'first_name' => $parts[0],
                'middle_name' => null,
                'last_name' => $parts[1],
            ];
        }

        return [
            'first_name' => $parts[0],
            'middle_name' => implode(' ', array_slice($parts, 1, -1)),
            'last_name' => end($parts),
        ];
    }
}
