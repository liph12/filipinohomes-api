<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mcp\Support\McpKeys;
use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Models\Agent;
use App\Models\McpCall;
use App\Models\McpKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Admin: the keys of the MCP server (Claude reading FH's numbers), from the
 * dashboard (System Settings > Claude access). Admin role only, see routes/api.php.
 * What the server is, and why a key can only read: docs/mcp.md.
 *
 * A key is shown ONCE, in the answer that makes it (never cached), and nowhere
 * else: only its hash is stored, so the list has the key's first characters and
 * nothing that could be used. An admin makes keys for themselves or for an
 * active agent (a key acts as its person: an agent's sees only their own
 * numbers); another admin makes their own. Who made and who revoked a key is on
 * the key, and every tool call is in mcp_calls (GET /admin/mcp-calls).
 */
class McpKeyController extends Controller
{
    /** GET /admin/mcp-keys: every key, newest first, and what a client needs to connect. */
    public function index(): JsonResponse
    {
        $keys = McpKey::query()->with('user.role', 'user.agent', 'creator')->orderByDesc('id')->limit(300)->get();

        $calls = McpCall::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->whereIn('mcp_key_id', $keys->pluck('id'))
            ->selectRaw('mcp_key_id, count(*) as n')
            ->groupBy('mcp_key_id')
            ->pluck('n', 'mcp_key_id');

        return response()->json([
            'data' => $keys->map(fn (McpKey $key): array => $this->present($key, (int) ($calls[$key->id] ?? 0)))->values(),
            'server' => [
                'url' => McpUrl::server(),
                'max_keys' => (int) config('fhmcp.max_keys', 3),
                'default_days' => (int) config('fhmcp.default_days', 30),
                'max_days' => (int) config('fhmcp.max_days', 90),
            ],
        ]);
    }

    /** POST /admin/mcp-keys: make a key. The key is in this answer and never again. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:80'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('fhmcp.max_days', 90)],
            // No end date: it works until it is revoked. Instead of days, never with them.
            'permanent' => ['sometimes', 'boolean'],
        ]);

        $admin = $request->user();
        $person = isset($data['user_id']) ? User::query()->with('role', 'agent')->find($data['user_id']) : $admin;

        if ($person === null) {
            return response()->json(['message' => 'There is no such person.', 'errors' => ['user_id' => ['There is no such person.']]], 422);
        }

        // A key for yourself, or for an active agent. Another admin makes their own.
        if ($person->id !== $admin->id && McpRole::of($person) !== McpRole::Agent) {
            return response()->json(['message' => 'A key can be made for yourself or for an active agent.', 'errors' => ['user_id' => ['A key can be made for yourself or for an active agent.']]], 422);
        }

        try {
            $issued = McpKeys::issue($person, $data['name'], $data['days'] ?? null, $admin, (bool) ($data['permanent'] ?? false));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['name' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'key' => $issued['key'],
            'url' => McpUrl::server(),
            'data' => $this->present($issued['record']->load('user.role', 'user.agent', 'creator')),
        ], 201, ['Cache-Control' => 'no-store, private']);
    }

    /** DELETE /admin/mcp-keys/{id}: switch a key off. It stops working on its next request. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $key = McpKey::query()->with('user.role', 'user.agent', 'creator')->find($id);

        if ($key === null) {
            return response()->json(['message' => 'There is no such key.'], 404);
        }

        McpKeys::revoke($key, $request->user());

        return response()->json(['data' => $this->present($key->refresh()->load('user.role', 'user.agent', 'creator'))]);
    }

    /** GET /admin/mcp-keys/people?q=: active agents to make a key for, by name or email. */
    public function people(Request $request): JsonResponse
    {
        $text = trim((string) $request->query('q', ''));

        if (mb_strlen($text) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.addcslashes($text, '%_\\').'%';

        $agents = Agent::query()
            ->with('user.role')
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereHas('user', fn ($user) => $user->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like))
            ->orderBy('last_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $agents
                ->filter(fn (Agent $agent): bool => $agent->user !== null && McpRole::of($agent->user) === McpRole::Agent)
                ->map(fn (Agent $agent): array => [
                    'user_id' => $agent->user->id,
                    'name' => $agent->user->name,
                    'email' => $agent->user->email,
                ])
                ->values(),
        ]);
    }

    /** GET /admin/mcp-calls?limit=&outcome=: what the keys were asked, newest first. */
    public function calls(Request $request): JsonResponse
    {
        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'outcome' => ['nullable', Rule::in(['ok', 'error', 'denied'])],
        ]);

        $rows = McpCall::query()
            ->leftJoin('users', 'users.id', '=', 'mcp_calls.user_id')
            ->leftJoin('mcp_keys', 'mcp_keys.id', '=', 'mcp_calls.mcp_key_id')
            ->select('mcp_calls.*', 'users.name as user_name', 'mcp_keys.name as key_name')
            ->when($request->filled('outcome'), fn ($query) => $query->where('mcp_calls.outcome', $request->query('outcome')))
            ->orderByDesc('mcp_calls.id')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (McpCall $call): array => [
                'id' => $call->id,
                'at' => $call->created_at?->toIso8601String(),
                'person' => ['id' => $call->user_id, 'name' => $call->getAttribute('user_name')],
                'role' => $call->role,
                'tool' => $call->tool,
                'outcome' => $call->outcome,
                'error' => $call->error,
                'arguments' => $call->arguments,
                'key_name' => $call->getAttribute('key_name'),
                'duration_ms' => $call->duration_ms,
                'bytes' => $call->bytes,
            ])->values(),
        ]);
    }

    /**
     * A key as the dashboard shows it: never the key itself, nor its hash.
     *
     * @return array<string, mixed>
     */
    private function present(McpKey $key, int $calls = 0): array
    {
        $user = $key->user;

        return [
            'id' => $key->id,
            'name' => $key->name,
            'key_start' => $key->prefix.'…',
            'state' => $key->state(),
            'person' => $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            // What the key can do right now: null once its person may no longer use the server.
            'acts_as' => $user === null ? null : McpRole::of($user)?->value,
            'created_by' => $key->creator === null ? null : ['id' => $key->creator->id, 'name' => $key->creator->name],
            'created_at' => $key->created_at?->toIso8601String(),
            // Null for a permanent key.
            'expires_at' => $key->expires_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'calls_7d' => $calls,
        ];
    }
}
