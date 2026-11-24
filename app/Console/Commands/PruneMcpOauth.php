<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\McpOauthRequest;
use App\Models\McpOauthToken;
use Illuminate\Console\Command;

/**
 * Clears out what signing Claude in leaves behind: sign-in requests that were
 * never answered or never finished, tokens that were spent or ran out long ago,
 * and connections that ended or expired months ago. Safe to run twice, and from
 * two servers at once: it only ever deletes what is already dead.
 */
class PruneMcpOauth extends Command
{
    protected $signature = 'fhmcp:prune';

    protected $description = 'Delete dead sign-in requests, spent tokens and long-ended connections';

    public function handle(): int
    {
        // A request is good for minutes; a day later nothing can use it.
        $requests = McpOauthRequest::query()->where('expires_at', '<', now()->subDay())->delete();

        // A pair whose refresh token was spent a week ago, or ran out a week ago, can no longer be told apart from any other.
        $tokens = McpOauthToken::query()
            ->where(fn ($query) => $query->where('rotated_at', '<', now()->subDays(7))->orWhere('refresh_expires_at', '<', now()->subDays(7)))
            ->delete();

        // Ended or expired three months ago; its tokens go with it.
        $connections = McpConnection::query()
            ->where(fn ($query) => $query->where('revoked_at', '<', now()->subDays(90))->orWhere('expires_at', '<', now()->subDays(90)))
            ->delete();

        $this->info("Deleted {$requests} sign-in requests, {$tokens} token pairs and {$connections} connections.");

        return self::SUCCESS;
    }
}
