<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Models\McpConnection;
use App\Services\McpOAuth\McpConnections;
use App\Services\McpOAuth\OAuthAuthorizations;
use App\Services\McpOAuth\OAuthClients;
use App\Services\McpOAuth\OAuthError;
use App\Services\McpOAuth\OAuthSwitch;
use App\Support\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the website needs to connect a person's Claude to their account:
 * whether they can, the page that asks them to confirm (describe, approve,
 * deny: the answer to Claude's sign-in request), and the connections they hold
 * (list them, end one). Everything here is the signed-in person's own.
 *
 * The website signs a person out when the API answers 401, so nothing here
 * ever does: a sign-in that is wrong, expired or already answered is 404, 410
 * or 409, and one the person may not make is 403.
 */
class ConnectController extends Controller
{
    /** GET /api/mcp/connect/status: can this person connect an AI (Claude, ChatGPT), and what do they hold. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        // Claude's own "add a connector" page, with Filipino Homes and our address already filled in.
        $link = 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName='.rawurlencode('Filipino Homes').'&connectorUrl='.rawurlencode(McpUrl::server());

        // Every agent's dashboard asks this on every load. While signing in is off there is nothing to look up.
        if (! OAuthSwitch::enabled()) {
            return response()->json(['mode' => OAuthSwitch::mode(), 'available' => false, 'role' => null, 'address' => McpUrl::server(), 'connect_link' => $link, 'providers' => $this->providers(null), 'connections' => []]);
        }

        $role = McpRole::of($user);

        return response()->json([
            'mode' => OAuthSwitch::mode(),
            'available' => $role !== null && OAuthSwitch::allows($role),
            'role' => $role?->value,
            'address' => McpUrl::server(),
            'connect_link' => $link,
            'providers' => $this->providers($role),
            'connections' => McpConnection::query()
                ->where('user_id', $user->id)
                ->active()
                ->orderByDesc('id')
                ->get()
                ->map(fn (McpConnection $connection): array => $this->present($connection))
                ->values(),
        ]);
    }

    /** GET /api/mcp/connect/requests/{handle}: who is asking, and which account would be connected. */
    public function describe(Request $request, string $handle, OAuthAuthorizations $authorizations): JsonResponse
    {
        try {
            return response()->json($authorizations->describe($handle, $request->user(), Impersonation::isImpersonated($request->user())), 200, ['Cache-Control' => 'no-store']);
        } catch (OAuthError $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/mcp/connect/requests/{handle}/approve: yes, for the account the page showed (account_id). Answers where to send the browser. */
    public function approve(Request $request, string $handle, OAuthAuthorizations $authorizations): JsonResponse
    {
        $shown = $request->validate(['account_id' => ['required', 'integer']])['account_id'];

        try {
            return response()->json(['redirect_to' => $authorizations->approve($handle, $request->user(), Impersonation::isImpersonated($request->user()), (int) $shown)], 200, ['Cache-Control' => 'no-store']);
        } catch (OAuthError $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/mcp/connect/requests/{handle}/deny: no. Answers where to send the browser. */
    public function deny(Request $request, string $handle, OAuthAuthorizations $authorizations): JsonResponse
    {
        try {
            return response()->json(['redirect_to' => $authorizations->deny($handle, $request->user())], 200, ['Cache-Control' => 'no-store']);
        } catch (OAuthError $e) {
            return $this->fail($e);
        }
    }

    /** DELETE /api/mcp/connect/connections/{id}: the person ends one of their own. */
    public function disconnect(Request $request, int $id): JsonResponse
    {
        $connection = McpConnection::query()->where('user_id', $request->user()->id)->find($id);

        if ($connection === null) {
            return response()->json(['message' => 'There is no such connection.'], 404);
        }

        McpConnections::end($connection, $request->user(), McpConnections::USER);

        return response()->json(['data' => $this->present($connection->refresh())]);
    }

    /**
     * The AIs, and whether this person may connect each right now: `mode` is that AI's own switch (Claude's is the
     * one the top-level `mode` and `available` are about, as they always were), `available` is whether the switches
     * let this person's role in.
     *
     * @return list<array{id: string, name: string, mode: string, available: bool}>
     */
    private function providers(?McpRole $role): array
    {
        $providers = [];

        foreach (OAuthClients::providers() as $id => $name) {
            $providers[] = [
                'id' => $id,
                'name' => $name,
                'mode' => OAuthSwitch::providerMode($id),
                'available' => $role !== null && OAuthSwitch::allows($role, OAuthClients::clientOf($id)),
            ];
        }

        return $providers;
    }

    /** @return array<string, mixed> */
    private function present(McpConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'client' => OAuthClients::name($connection->client_id),
            'provider' => OAuthClients::providerOf($connection->client_id),
            'created_at' => $connection->created_at?->toIso8601String(),
            'last_used_at' => $connection->last_used_at?->toIso8601String(),
            'state' => $connection->state(),
            // Made for an address this API is no longer at: Claude has to be connected again.
            'needs_reconnect' => $connection->needsReconnect(),
        ];
    }

    private function fail(OAuthError $e): JsonResponse
    {
        return response()->json($e->body() + ['message' => $e->getMessage()], $e->status, ['Cache-Control' => 'no-store']);
    }
}
