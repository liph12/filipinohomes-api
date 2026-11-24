<?php

namespace App\Services\McpOAuth;

use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Models\McpOauthRequest;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The first half of signing in: Claude sends the person's browser to
 * /oauth/authorize, the request is checked and kept (start), the website shows
 * the person which account is asking and what they are allowing (describe), and
 * they answer (approve or deny), which sends the browser back to Claude with a
 * one-time code, or with "access_denied".
 *
 * The browser is only ever sent back to a return address on OAuthClients' list,
 * and only after it has been checked; every other mistake is shown on a page
 * of ours and goes nowhere. Nothing here is remembered between sign-ins: the
 * person is asked every time.
 */
final class OAuthAuthorizations
{
    /** How long a person has to answer. */
    public const REQUEST_MINUTES = 15;

    /** How long Claude has to trade the code for tokens. */
    public const CODE_SECONDS = 60;

    /**
     * A flood guard for the table, not for any one address: sign-ins waiting for an answer, all at once (answered
     * ones no longer count). Set far above what people do, so that filling it takes a sustained flood.
     */
    private const MAX_WAITING = 20000;

    /**
     * Checks what Claude asked for and keeps it. Returns the handle the website is given.
     *
     * @param  array<string, mixed>  $query  the query string exactly as it was sent
     *
     * @throws OAuthError redirectable only once the return address has been found good
     */
    public function start(array $query): string
    {
        $clientId = is_string($query['client_id'] ?? null) ? $query['client_id'] : '';
        $redirect = is_string($query['redirect_uri'] ?? null) ? $query['redirect_uri'] : '';

        // Until the return address is known to be good, a mistake is shown, never redirected.
        if (! OAuthClients::exists($clientId) || ! OAuthClients::allowsRedirect($clientId, $redirect)) {
            throw new OAuthError('invalid_request', 'This sign-in link is not valid.');
        }

        $state = self::state($query);

        if ($state === false) {
            throw new OAuthError('invalid_request', 'state is not valid.', 400, true);
        }

        if (($query['response_type'] ?? null) !== 'code') {
            throw new OAuthError('unsupported_response_type', 'Only response_type=code is supported.', 400, true);
        }

        $challenge = $query['code_challenge'] ?? null;

        if (! is_string($challenge) || ! Pkce::isChallenge($challenge)) {
            throw new OAuthError('invalid_request', 'code_challenge is missing or not valid.', 400, true);
        }

        if (($query['code_challenge_method'] ?? null) !== 'S256') {
            throw new OAuthError('invalid_request', 'code_challenge_method must be S256.', 400, true);
        }

        $resource = $query['resource'] ?? null;

        if ($resource !== null && ! (is_string($resource) && self::isOurAddress($resource))) {
            throw new OAuthError('invalid_target', 'resource is not this server.', 400, true);
        }

        if (McpOauthRequest::query()->whereNull('decision')->where('expires_at', '>', now())->count() >= self::MAX_WAITING) {
            throw new OAuthError('temporarily_unavailable', 'Too many sign-ins are waiting. Try again in a few minutes.', 503, true);
        }

        $handle = Str::random(43);

        McpOauthRequest::query()->create([
            'handle_hash' => hash('sha256', $handle),
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'state' => $state,
            'code_challenge' => $challenge,
            'scope' => 'fh:read',
            'resource' => $resource === null ? null : McpUrl::canonical($resource),
            'expires_at' => now()->addMinutes(self::REQUEST_MINUTES),
        ]);

        return $handle;
    }

    /**
     * What the website shows the person.
     *
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public function describe(string $handle, User $user, bool $impersonated): array
    {
        $request = $this->find($handle);
        $role = McpRole::of($user);
        $why = $this->refusal($request, $role, $impersonated);

        return [
            'client' => [
                'name' => OAuthClients::name($request->client_id),
                'kind' => OAuthClients::kind($request->client_id),
                'return_host' => (string) parse_url($request->redirect_uri, PHP_URL_HOST),
            ],
            'account' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $role?->value],
            'allowed' => $why === null,
            'reason' => $why,
            // An admin's connection opens the whole company's reports, and a loopback one is a program on a computer.
            'press_and_hold' => $role === McpRole::Admin || OAuthClients::kind($request->client_id) === OAuthClients::KIND_LOOPBACK,
            'expires_at' => $request->expires_at->toIso8601String(),
        ];
    }

    /**
     * The person says yes: a one-time code is made and the browser goes back to Claude with it. It is the
     * account the page showed that says yes (a second tab may have signed in as someone else since).
     *
     * @return string where to send the browser
     *
     * @throws OAuthError
     */
    public function approve(string $handle, User $user, bool $impersonated, int $shownAccount): string
    {
        if ($shownAccount !== $user->id) {
            throw new OAuthError('account_changed', 'The account has changed. Please check it and try again.', 403);
        }

        $request = $this->find($handle);
        $role = McpRole::of($user);
        $why = $this->refusal($request, $role, $impersonated);

        if ($why !== null || $role === null) {
            throw new OAuthError('not_allowed', self::reasonText($why ?? 'no_role'), 403);
        }

        $code = Str::random(48);

        $answered = McpOauthRequest::query()->whereKey($request->id)->whereNull('decision')->update([
            'decision' => McpOauthRequest::APPROVED,
            'user_id' => $user->id,
            'role' => $role->value,
            'code_hash' => hash('sha256', $code),
            'code_expires_at' => now()->addSeconds(self::CODE_SECONDS),
        ]);

        if ($answered !== 1) {
            throw new OAuthError('answered', 'This sign-in has already been answered.', 409);
        }

        return self::back($request, ['code' => $code]);
    }

    /**
     * The person says no: the browser goes back to Claude with access_denied.
     *
     * @throws OAuthError
     */
    public function deny(string $handle, User $user): string
    {
        $request = $this->find($handle);

        $answered = McpOauthRequest::query()->whereKey($request->id)->whereNull('decision')->update([
            'decision' => McpOauthRequest::DENIED,
            'user_id' => $user->id,
        ]);

        if ($answered !== 1) {
            throw new OAuthError('answered', 'This sign-in has already been answered.', 409);
        }

        return self::back($request, ['error' => 'access_denied', 'error_description' => 'The person did not allow it.']);
    }

    /**
     * Where the browser goes when Claude's request itself was wrong but its return address was good.
     *
     * @param  array<string, mixed>  $query
     */
    public static function errorRedirect(array $query, OAuthError $error): string
    {
        $state = self::state($query);

        return self::append((string) $query['redirect_uri'], [
            'error' => $error->error,
            'error_description' => $error->getMessage(),
            ...($state === false || $state === null ? [] : ['state' => $state]),
        ]);
    }

    /** @throws OAuthError */
    private function find(string $handle): McpOauthRequest
    {
        $request = McpOauthRequest::query()->where('handle_hash', hash('sha256', $handle))->first();

        if ($request === null) {
            throw new OAuthError('not_found', 'This sign-in link is not valid.', 404);
        }

        if ($request->expires_at->isPast()) {
            throw new OAuthError('expired', 'This sign-in has expired. Start again from Claude.', 410);
        }

        if ($request->decision !== null) {
            throw new OAuthError('answered', 'This sign-in has already been answered.', 409);
        }

        return $request;
    }

    /** Why this person may not allow it, or null when they may. */
    private function refusal(McpOauthRequest $request, ?McpRole $role, bool $impersonated): ?string
    {
        return match (true) {
            // An admin looking at an agent's account through the "log in as" feature must not connect Claude for them.
            $impersonated => 'impersonated',
            $role === null => 'no_role',
            ! OAuthSwitch::allows($role) => 'paused',
            OAuthClients::kind($request->client_id) === OAuthClients::KIND_LOOPBACK && $role !== McpRole::Admin => 'admins_only',
            default => null,
        };
    }

    public static function reasonText(string $reason): string
    {
        return match ($reason) {
            'impersonated' => 'Sign in as yourself, not through another account, to connect Claude.',
            'paused' => 'Connecting Claude is not open to your account yet.',
            'admins_only' => 'This kind of connection is not open to your account.',
            default => 'This account cannot connect Claude.',
        };
    }

    /**
     * The state, exactly as it was sent; null when there is none; false when it is not usable
     * (too long, or anything beyond printable characters).
     *
     * @param  array<string, mixed>  $query
     */
    private static function state(array $query): string|false|null
    {
        $state = $query['state'] ?? null;

        if ($state === null) {
            return null;
        }

        return is_string($state) && strlen($state) <= 1024 && preg_match('/[^\x20-\x7E]/', $state) === 0 ? $state : false;
    }

    private static function isOurAddress(string $resource): bool
    {
        return McpUrl::isAccepted($resource);
    }

    /** @param  array<string, string>  $params */
    private static function back(McpOauthRequest $request, array $params): string
    {
        return self::append($request->redirect_uri, [...$params, ...($request->state === null ? [] : ['state' => $request->state])]);
    }

    /** @param  array<string, string>  $params */
    private static function append(string $uri, array $params): string
    {
        $pairs = [];

        foreach ($params as $name => $value) {
            $pairs[] = rawurlencode($name).'='.rawurlencode($value);
        }

        return $uri.(str_contains($uri, '?') ? '&' : '?').implode('&', $pairs);
    }
}
