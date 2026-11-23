<?php

namespace App\Console\Commands;

use App\Mcp\Support\McpRole;
use App\Models\McpKey;
use Illuminate\Console\Command;

/**
 * Lists the MCP keys and whose they are. The key itself is not kept, so what
 * shows is its first characters, enough to tell one from another.
 */
class ListMcpKeys extends Command
{
    protected $signature = 'fhmcp:keys
        {--user= : Only this person\'s keys (their email)}
        {--all : Include keys that are revoked or expired}';

    protected $description = 'List the MCP keys, who they belong to, and when they were last used';

    public function handle(): int
    {
        $keys = McpKey::query()
            ->with('user.role', 'user.agent')
            ->when($this->option('user'), fn ($query, $email) => $query->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $email)])))
            ->unless($this->option('all'), fn ($query) => $query->active())
            ->orderByDesc('id')
            ->get();

        if ($keys->isEmpty()) {
            $this->components->info('No keys.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Person', 'Acts as', 'Key', 'Name', 'Works until (UTC)', 'Last used (UTC)', 'State'],
            $keys->map(fn (McpKey $key): array => [
                $key->id,
                $key->user?->email ?? '(deleted)',
                $key->user ? (McpRole::of($key->user)?->value ?? 'no access') : '-',
                $key->prefix.'…',
                $key->name,
                $key->expires_at?->toDateTimeString() ?? 'never (permanent)',
                $key->last_used_at?->toDateTimeString() ?? 'never',
                $key->state(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
