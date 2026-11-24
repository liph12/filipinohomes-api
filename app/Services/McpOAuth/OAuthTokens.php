<?php

namespace App\Services\McpOAuth;

use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Models\McpConnection;
use App\Models\McpOauthRequest;
use App\Models\McpOauthToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The second half of signing in, and everything after it: Claude trades the
 * one-time code for tokens (redeem), trades a refresh token for the next pair
 * before the access token runs out (refresh), says it is done (revoke), and
 * every request to the MCP server is checked against the access token
 * (findAccess). Only the SHA-256 of a token is kept.
 *
 * The rules that matter, because Claude does odd things (it has been seen to
 * send the same refresh twice a few milliseconds apart, to send an old one
 * hours later, and to sign in again without telling us the old connection is
 * gone):
 *
 *  - A code is spent before anything about it is checked, so it can be tried
 *    once. A second try is refused and the connection it made is left alone.
 *  - A refresh token is spent by using it, and the next pair is made. Use of an
 *    old one again is a retry unless the newer pair has since been used, in
 *    which case someone other than the holder of the newer pair has the old
 *    token, and the connection is ended.
 */
final class OAuthTokens
{
    public const ACCESS_PREFIX = 'fhoat_';

    public const REFRESH_PREFIX = 'fhort_';

    /**
     * An old refresh token used again this soon after it was spent is a retry, whatever happened since: Claude has
     * been seen to send the same one twice a few milliseconds apart, and to ask again when an answer was lost.
     */
    private const RETRY_SECONDS = 30;

    /**
     * What the token endpoint answers.
     *
     * @param  array<string, mixed>  $form  the form body of the request
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public function grant(array $form): array
    {
        return match ($form['grant_type'] ?? null) {
            'authorization_code' => $this->redeem($form),
            'refresh_token' => $this->refresh($form),
            default => throw new OAuthError('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.'),
        };
    }

    /**
     * The pair of tokens a connection holds is looked up by its access token.
     * Null when there is no such token; whether it is still good is the caller's to judge.
     */
    public function findAccess(string $token): ?McpOauthToken
    {
        return McpOauthToken::query()
            ->with('connection.user.role', 'connection.user.agent')
            ->where('access_hash', hash('sha256', $token))
            ->first();
    }

    /** Claude is done with a token: its connection ends (RFC 7009). Nothing is said about whether it was one. */
    public function revoke(array $form): void
    {
        $token = is_string($form['token'] ?? null) ? $form['token'] : '';

        if ($token === '') {
            return;
        }

        $hash = hash('sha256', $token);
        $pair = McpOauthToken::query()->with('connection')->where('access_hash', $hash)->orWhere('refresh_hash', $hash)->first();

        if ($pair?->connection !== null) {
            McpConnections::end($pair->connection, null, McpConnections::CLAUDE);
        }
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function redeem(array $form): array
    {
        $code = self::text($form, 'code');
        $verifier = self::text($form, 'code_verifier');
        $redirect = self::text($form, 'redirect_uri');

        if ($code === '' || $redirect === '' || ! Pkce::isVerifier($verifier)) {
            throw new OAuthError('invalid_request', 'code, redirect_uri and code_verifier are required.');
        }

        $request = McpOauthRequest::query()->where('code_hash', hash('sha256', $code))->first();

        if ($request === null) {
            throw new OAuthError('invalid_grant', 'That code is not valid.');
        }

        // Spent first, then checked: a code can be tried once, whatever is wrong with the try.
        $spent = McpOauthRequest::query()
            ->whereKey($request->id)
            ->where('decision', McpOauthRequest::APPROVED)
            ->whereNull('code_used_at')
            ->where('code_expires_at', '>', now())
            ->update(['code_used_at' => now()]);

        if ($spent !== 1) {
            Log::info('fhmcp.oauth.code_refused', ['request' => $request->id]);

            throw new OAuthError('invalid_grant', 'That code is not valid.');
        }

        $clientId = self::text($form, 'client_id');
        $resource = self::text($form, 'resource');

        if (($clientId !== '' && $clientId !== $request->client_id)
            || ! hash_equals($request->redirect_uri, $redirect)
            || ! Pkce::matches($verifier, $request->code_challenge)) {
            throw new OAuthError('invalid_grant', 'That code is not valid.');
        }

        if ($resource !== '' && ! self::sameAddress($resource, $request->resource)) {
            throw new OAuthError('invalid_target', 'resource is not the one the sign-in was for.');
        }

        $user = User::query()->with('role', 'agent')->find($request->user_id);
        $role = $user === null ? null : McpRole::of($user);

        // The person has to be what they were when they said yes, and still allowed to be.
        if ($user === null || $role === null || $role->value !== $request->role || ! OAuthSwitch::allows($role)) {
            throw new OAuthError('invalid_grant', 'That code is not valid.');
        }

        // The connection and its first pair are made together: one that failed halfway would be a connection
        // that counts toward the person's limit for months and has no tokens.
        [$connection, $response] = DB::transaction(function () use ($request, $user, $role): array {
            $connection = McpConnection::query()->create([
                'user_id' => $user->id,
                'client_id' => $request->client_id,
                'client_kind' => OAuthClients::kind($request->client_id),
                'role' => $role->value,
                'resource' => $request->resource ?? (McpUrl::canonical(McpUrl::server()) ?? McpUrl::server()),
                'scope' => 'fh:read',
                'expires_at' => self::connectionEnds(),
            ]);

            McpOauthRequest::query()->whereKey($request->id)->update(['mcp_connection_id' => $connection->id]);
            McpConnections::retireBeyondCap($user, $connection);

            return [$connection, $this->mint($connection, null)];
        });

        McpConnections::tellConnected($user, $connection);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function refresh(array $form): array
    {
        $refresh = self::text($form, 'refresh_token');

        if ($refresh === '' || ! str_starts_with($refresh, self::REFRESH_PREFIX)) {
            throw new OAuthError('invalid_grant', 'That refresh token is not valid.');
        }

        $clientId = self::text($form, 'client_id');
        $hash = hash('sha256', $refresh);

        // An ending has to be kept even though the answer is a refusal, so the refusal leaves the transaction as a value.
        $result = DB::transaction(function () use ($hash, $clientId): array|OAuthError {
            $pair = McpOauthToken::query()->where('refresh_hash', $hash)->lockForUpdate()->first();
            $connection = $pair === null ? null : McpConnection::query()->with('user.role', 'user.agent')->lockForUpdate()->find($pair->mcp_connection_id);

            if ($pair === null || $connection === null || ! $connection->isActive() || ($clientId !== '' && $clientId !== $connection->client_id)) {
                return new OAuthError('invalid_grant', 'That refresh token is not valid.');
            }

            $role = $connection->user === null ? null : McpRole::of($connection->user);

            // No role on the server any more (switched off, say). Coming back later does not bring the connection back.
            if ($role === null) {
                McpConnections::end($connection, null, McpConnections::NO_ACCESS);

                return new OAuthError('invalid_grant', 'That refresh token is not valid.');
            }

            // Not what they were when they connected: what Claude can see would not match what they agreed to.
            if ($role->value !== $connection->role) {
                McpConnections::end($connection, null, McpConnections::ROLE_CHANGED);

                return new OAuthError('invalid_grant', 'Connect Claude again to continue.');
            }

            if ($pair->rotated_at === null) {
                $pair->forceFill(['rotated_at' => now(), 'used_at' => $pair->used_at ?? now()])->save();

                return $this->next($connection, $pair);
            }

            // An old refresh token again. A retry (or the other half of two requests sent together) gets a pair too.
            if ($pair->rotated_at->gte(now()->subSeconds(self::RETRY_SECONDS))
                || ! McpOauthToken::query()->where('parent_id', $pair->id)->whereNotNull('used_at')->exists()) {
                return $this->next($connection, $pair);
            }

            // The newer pair has been used and this one is still being presented: it is in other hands.
            McpConnections::end($connection, null, McpConnections::REFRESH_REUSE);

            return new OAuthError('invalid_grant', 'That refresh token is not valid.');
        });

        if ($result instanceof OAuthError) {
            throw $result;
        }

        return $result;
    }

    /**
     * The next pair for a connection: its end slides forward, as does the refresh token's, but never past
     * the longest a connection may last.
     *
     * @return array<string, mixed>
     */
    private function next(McpConnection $connection, McpOauthToken $parent): array
    {
        $connection->forceFill(['expires_at' => self::connectionEnds($connection->created_at)])->save();

        return $this->mint($connection, $parent);
    }

    /**
     * When a connection made at $madeAt (now, for a new one) stops unless it is used: the refresh window from
     * today, or the longest a connection may last from the day it was made, whichever comes first.
     */
    private static function connectionEnds(?CarbonInterface $madeAt = null): CarbonInterface
    {
        $slides = now()->addDays(max(1, (int) config('fhmcp.refresh_days', 60)));
        $longest = ($madeAt ?? now())->copy()->addDays(max(1, (int) config('fhmcp.max_connection_days', 180)));

        return $slides->lessThan($longest) ? $slides : $longest;
    }

    /**
     * Makes a pair of tokens and the answer that carries them.
     *
     * @return array<string, mixed>
     */
    private function mint(McpConnection $connection, ?McpOauthToken $parent): array
    {
        $access = self::ACCESS_PREFIX.Str::random(48);
        $refresh = self::REFRESH_PREFIX.Str::random(64);
        $accessEnds = now()->addHours(max(1, (int) config('fhmcp.access_token_hours', 8)));

        McpOauthToken::query()->create([
            'mcp_connection_id' => $connection->id,
            'parent_id' => $parent?->id,
            'access_hash' => hash('sha256', $access),
            'access_expires_at' => $accessEnds,
            'refresh_hash' => hash('sha256', $refresh),
            'refresh_expires_at' => $connection->expires_at,
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => max(60, (int) now()->diffInSeconds($accessEnds, true)),
            'refresh_token' => $refresh,
            'scope' => $connection->scope,
        ];
    }

    /** @param  array<string, mixed>  $form */
    private static function text(array $form, string $name): string
    {
        return is_string($form[$name] ?? null) ? $form[$name] : '';
    }

    /** Whether an address is the one the sign-in was for (or, when it named none, one this API answers to). */
    private static function sameAddress(string $given, ?string $wanted): bool
    {
        return $wanted !== null ? McpUrl::same($given, $wanted) : McpUrl::isAccepted($given);
    }
}
