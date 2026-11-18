<?php

namespace App\Services\LeuterioreRealty;

use App\Support\LrAgentStatus;
use Illuminate\Support\Facades\Http;

class LrApiService
{
    public const LOOKUP_FOUND = 'found';
    public const LOOKUP_NOT_FOUND = 'not_found';
    public const LOOKUP_ERROR = 'error';

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

    /**
     * What LR says about one agent's status, as a discriminated result.
     *
     * fetchAgentByEmail() collapses a miss, a 429 and an outage into null, which
     * is fine for a login path and wrong here: this feeds a sync that demotes
     * people, and reading "LR is rate-limiting us" as "this agent left" would
     * hide a working agent's listings. So only a clean answer counts:
     *
     *   found     — HTTP 200 with a top-level `status` (its value may be null)
     *               in a payload that names the address asked about.
     *               `status` comes back normalised; '' when LR has none.
     *   not_found — {"success":false,"message":"User not found."} (HTTP 403 today).
     *               About 58% of FH's agents get this, so it never means "left".
     *   error     — 429, 5xx, any other 403, a timeout, a payload without
     *               `status`, or one about a different address. Retry later;
     *               never act on it.
     *
     * `remaining` is LR's X-RateLimit-Remaining (60 requests a minute per IP,
     * shared with real sign-ins), so the caller can back off before it starves
     * them. Raw payloads are never returned: they carry personal data.
     *
     * @return array{result: string, status: ?string, http_status: ?int, remaining: ?int, error: ?string}
     */
    public function lookupAgentStatus(string $email): array
    {
        $email = strtolower(trim($email));

        try {
            // A hit routinely takes 2–12 s; a miss answers in about 0.3 s.
            $res = Http::timeout(30)->acceptJson()->get(self::BASE_URL . '/' . urlencode($email));
        } catch (\Throwable $e) {
            return $this->statusLookup(self::LOOKUP_ERROR, error: 'transport: ' . $e->getMessage());
        }

        $code = $res->status();
        $remaining = is_numeric($header = $res->header('X-RateLimit-Remaining')) ? (int) $header : null;
        $body = $res->json();

        if (is_array($body) && ($body['success'] ?? null) === false && ($body['message'] ?? null) === 'User not found.') {
            return $this->statusLookup(self::LOOKUP_NOT_FOUND, http: $code, remaining: $remaining);
        }

        if (! $res->successful()) {
            return $this->statusLookup(self::LOOKUP_ERROR, http: $code, remaining: $remaining, error: "http {$code}");
        }

        if (! is_array($body) || ! array_key_exists('status', $body)) {
            return $this->statusLookup(self::LOOKUP_ERROR, http: $code, remaining: $remaining, error: 'malformed payload');
        }

        // The answer must be about the agent we asked about. A payload for a
        // different address — or one that doesn't say whose it is — is never
        // trusted: demoting someone on another person's record is the one
        // mistake a retry can't undo. (LR echoes the address back; 22 of 22
        // sampled hits matched.)
        $about = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        if ($about !== $email) {
            return $this->statusLookup(self::LOOKUP_ERROR, http: $code, remaining: $remaining, error: 'identity mismatch');
        }

        return $this->statusLookup(
            self::LOOKUP_FOUND,
            status: LrAgentStatus::normalize($body['status']),
            http: $code,
            remaining: $remaining,
        );
    }

    /** @return array{result: string, status: ?string, http_status: ?int, remaining: ?int, error: ?string} */
    private function statusLookup(string $result, ?string $status = null, ?int $http = null, ?int $remaining = null, ?string $error = null): array
    {
        return [
            'result' => $result,
            'status' => $status,
            'http_status' => $http,
            'remaining' => $remaining,
            'error' => $error !== null ? substr($error, 0, 500) : null,
        ];
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
