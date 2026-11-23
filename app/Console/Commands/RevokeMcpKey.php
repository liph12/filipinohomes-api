<?php

namespace App\Console\Commands;

use App\Mcp\Support\McpKeys;
use App\Models\McpKey;
use Illuminate\Console\Command;

/**
 * Switches MCP keys off, at once: one by its number (see fhmcp:keys), or every
 * key of one person (when someone leaves, or a key may have leaked).
 */
class RevokeMcpKey extends Command
{
    protected $signature = 'fhmcp:revoke-key
        {id? : The key\'s number from fhmcp:keys}
        {--user= : Revoke every key of this person instead (their email)}';

    protected $description = 'Revoke one MCP key, or all of one person\'s keys';

    public function handle(): int
    {
        $id = $this->argument('id');
        $email = $this->option('user');

        if (($id === null) === ($email === null)) {
            $this->components->error('Say which: a key number, or --user=<email>, not both and not neither.');

            return self::FAILURE;
        }

        $keys = McpKey::query()
            ->whereNull('revoked_at')
            ->when($id !== null, fn ($query) => $query->whereKey((int) $id))
            ->when($email !== null, fn ($query) => $query->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $email)])))
            ->get();

        if ($keys->isEmpty()) {
            $this->components->warn('No key in use matches that.');

            return self::FAILURE;
        }

        $keys->each(fn (McpKey $key) => McpKeys::revoke($key));

        $this->components->info("Revoked {$keys->count()} key(s). They stop working on their next request.");

        return self::SUCCESS;
    }
}
