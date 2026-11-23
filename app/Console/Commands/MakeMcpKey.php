<?php

namespace App\Console\Commands;

use App\Mcp\Support\McpKeys;
use App\Mcp\Support\McpRole;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Makes a key for the MCP server (Claude reading FH's numbers) for one person
 * and prints it ONCE. Only its hash is stored, so it can't be shown again: a
 * lost key is revoked and replaced. See docs/mcp.md.
 */
class MakeMcpKey extends Command
{
    protected $signature = 'fhmcp:make-key
        {email : The FH account the key acts as. Only an admin or an active agent can have one}
        {--name= : What the key is for, e.g. "Claude Code on my laptop"}
        {--days= : How many days it works (default and longest are in config/fhmcp.php)}';

    protected $description = 'Make an MCP key for an admin or an active agent and print it once';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::query()->with('role', 'agent')->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if ($user === null) {
            $this->components->error("No account has the email {$email}.");

            return self::FAILURE;
        }

        $role = McpRole::of($user);

        if ($role === null) {
            $this->components->error('That account cannot have an MCP key: only an admin or an active agent can.');

            return self::FAILURE;
        }

        try {
            ['key' => $key, 'record' => $record] = McpKeys::issue(
                $user,
                (string) ($this->option('name') ?: 'MCP key made on the server'),
                $this->option('days') === null ? null : (int) $this->option('days'),
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Key #{$record->id} for {$user->email}, acting as {$role->value}. It works until {$record->expires_at->toDateTimeString()} UTC.");
        $this->newLine();
        $this->line('It is shown only now. Put it in the connector or a password manager, never in a chat, a ticket or Git:');
        $this->newLine();
        $this->line('   '.$key);
        $this->newLine();
        $this->line('Server: '.url('/mcp/filipinohomes'));
        $this->line('Send it as the header   Authorization: Bearer <the key>');
        $this->newLine();
        $this->line($role === McpRole::Admin
            ? 'This key sees the whole company\'s reports and nothing else. It can change nothing.'
            : 'This key sees only this agent\'s own numbers and listings. It can change nothing.');

        return self::SUCCESS;
    }
}
