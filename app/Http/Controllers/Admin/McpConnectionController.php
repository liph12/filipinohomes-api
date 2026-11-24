<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mcp\Support\McpUrl;
use App\Models\McpCall;
use App\Models\McpConnection;
use App\Models\McpKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\McpOAuth\McpConnections;
use App\Services\McpOAuth\OAuthClients;
use App\Services\McpOAuth\OAuthSwitch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Admin: people's Claude connections and the switch that lets them be made
 * (System Settings > Claude access). Admin role only, see routes/api.php.
 * What a connection is, and what the switch does: docs/mcp.md, "Sign-in".
 *
 * An admin can see every connection and end any of them, and end all of them
 * at once; neither is a side effect of the switch, which only decides who may
 * connect and use one. They can also tell everyone who is affected, once, that
 * the address Claude connects to has changed.
 */
class McpConnectionController extends Controller
{
    private const NOTIFIED = 'fhmcp.address_notified';

    /** GET /admin/mcp-oauth: the switch, the address, and who a change of address would touch. */
    public function show(): JsonResponse
    {
        return response()->json($this->state());
    }

    /** PUT /admin/mcp-oauth: set the switch to off, admins or on. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(OAuthSwitch::MODES)]]);

        OAuthSwitch::set($data['mode']);

        return response()->json($this->state());
    }

    /** GET /admin/mcp-connections?q=&page=: connections, newest first, a page at a time. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1']]);

        $text = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($text, '%_\\').'%';

        $page = McpConnection::query()
            ->with('user:id,name,email')
            ->when($text !== '', fn ($query) => $query->whereHas('user', fn ($user) => $user->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->orderByDesc('id')
            ->paginate(25);

        $calls = McpCall::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->whereIn('mcp_connection_id', $page->getCollection()->pluck('id'))
            ->selectRaw('mcp_connection_id, count(*) as n')
            ->groupBy('mcp_connection_id')
            ->pluck('n', 'mcp_connection_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (McpConnection $connection): array => $this->present($connection, (int) ($calls[$connection->id] ?? 0)))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** DELETE /admin/mcp-connections/{id}: end one. Claude would have to sign in again. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $connection = McpConnection::query()->with('user:id,name,email')->find($id);

        if ($connection === null) {
            return response()->json(['message' => 'There is no such connection.'], 404);
        }

        McpConnections::end($connection, $request->user(), McpConnections::ADMIN);

        return response()->json(['data' => $this->present($connection->refresh()->load('user:id,name,email'))]);
    }

    /** POST /admin/mcp-connections/disconnect-all: end every connection that is still good. */
    public function disconnectAll(Request $request): JsonResponse
    {
        $ended = 0;

        // By id, not by page: ending a connection takes it out of the set being walked, and a page-by-page walk
        // would skip the rows that slide into the pages already passed.
        McpConnection::query()->active()->chunkById(500, function ($connections) use ($request, &$ended): void {
            foreach ($connections as $connection) {
                McpConnections::end($connection, $request->user(), McpConnections::ADMIN);
                $ended++;
            }
        });

        return response()->json(['ended' => $ended]);
    }

    /**
     * POST /admin/mcp-oauth/notify-address-change: a bell notification (and a push) to each person whose
     * Claude was connected at an address that is no longer the current one, and to key holders when an
     * address has been retired. Once for each person for the address that is current.
     */
    public function notifyAddressChange(): JsonResponse
    {
        // One run at a time (on this server; the setting below is what a second server goes by).
        $lock = Cache::lock('fhmcp-address-notify', 120);

        if (! $lock->get()) {
            return response()->json(['message' => 'These are already being sent. Try again in a moment.'], 409);
        }

        try {
            $current = (string) McpUrl::canonical(McpUrl::server());
            $done = json_decode((string) Setting::get(self::NOTIFIED, '{}'), true);
            $done = is_array($done) ? $done : [];
            $already = array_map('intval', (array) ($done[$current] ?? []));
            $told = $already;
            $notified = 0;
            $failed = 0;

            $people = User::query()->whereIn('id', $this->affectedIds())->whereNotIn('id', $already)->get();

            // What is remembered is who was reached, a batch at a time: a person it failed for is asked again next time,
            // and a run that dies halfway does not tell everyone it got to again.
            foreach ($people->chunk(25) as $batch) {
                foreach ($batch as $user) {
                    if (McpConnections::tell($user, 'Claude address changed', 'The address Claude connects to has changed. Please connect Claude again from your portal.', ['type' => 'mcp_address_changed'])) {
                        $told[] = $user->id;
                        $notified++;
                    } else {
                        $failed++;
                    }
                }

                // Only the current address is kept, so a later change starts clean.
                Setting::set(self::NOTIFIED, json_encode([$current => array_values(array_unique($told))]));
            }

            return response()->json(['notified' => $notified, 'already_notified' => count($already), 'failed' => $failed]);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return [
            'mode' => OAuthSwitch::mode(),
            // The switch is on but the address is not one Claude can sign in to (not https): nothing answers.
            'address_usable' => OAuthSwitch::addressIsUsable(),
            'enabled' => OAuthSwitch::enabled(),
            'address' => McpUrl::server(),
            'former_addresses' => array_values((array) config('fhmcp.former_urls', [])),
            'consent_url' => McpUrl::consent(),
            'connections' => [
                'active' => McpConnection::query()->active()->count(),
                'needing_reconnect' => $this->staleConnections()->count(),
            ],
            'affected_people' => count($this->affectedIds()),
        ];
    }

    /** Active connections made for an address other than the current one (a connection's address is kept in its canonical form). */
    private function staleConnections(): Builder
    {
        return McpConnection::query()->active()->where('resource', '!=', (string) McpUrl::canonical(McpUrl::server()));
    }

    /**
     * The people an address change touches: those whose connection was made for another address, and, once
     * an address has been retired, everyone who holds a key (they typed the old address into Claude).
     *
     * @return list<int>
     */
    private function affectedIds(): array
    {
        $ids = $this->staleConnections()->distinct()->pluck('user_id');

        if ((array) config('fhmcp.former_urls', []) !== []) {
            $ids = $ids->merge(McpKey::query()->active()->pluck('user_id'));
        }

        return User::query()->whereIn('id', $ids->unique()->values()->all())->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    private function present(McpConnection $connection, int $calls = 0): array
    {
        $user = $connection->user;

        return [
            'id' => $connection->id,
            'person' => $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'role' => $connection->role,
            'client' => OAuthClients::name($connection->client_id),
            'state' => $connection->state(),
            'needs_reconnect' => $connection->needsReconnect(),
            'created_at' => $connection->created_at?->toIso8601String(),
            'last_used_at' => $connection->last_used_at?->toIso8601String(),
            'expires_at' => $connection->expires_at->toIso8601String(),
            'revoked_at' => $connection->revoked_at?->toIso8601String(),
            'revoked_reason' => $connection->revoked_reason,
            'calls_7d' => $calls,
        ];
    }
}
