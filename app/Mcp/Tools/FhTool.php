<?php

namespace App\Mcp\Tools;

use App\Mcp\Policies\McpPolicy;
use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpAudit;
use App\Mcp\Support\McpOutput;
use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpUrl;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Throwable;

/**
 * What every tool of FH's MCP server has in common, so that no tool has to
 * remember the safe way to do it.
 *
 *  - Read-only is not something a tool opts into: annotations() says so for
 *    all of them, and a tool only returns data (answer()). Nothing here, or
 *    in any tool, writes (a test reads the tools' code for it).
 *  - A tool belongs to one role (policy()->role()). It is listed only for that
 *    role (and for a role that covers it: a team leader is an agent too), and
 *    handle() checks the role again, because a tool the model was
 *    never shown can still be named in a call.
 *  - The arguments are validated, and only what passed validation reaches the
 *    tool: an argument the tool did not declare is dropped, not trusted.
 *  - What goes back is scrubbed of contact details and capped (McpOutput).
 *  - A mistake of the caller's is told plainly; anything else is reported to
 *    us and answered with a message that shows nothing of the inside.
 *  - Every call is on record (McpAudit).
 */
abstract class FhTool extends Tool
{
    abstract protected function policy(): McpPolicy;

    /** @return array<string, mixed> validation rules for the arguments */
    abstract protected function rules(): array;

    /**
     * @param  array<string, mixed>  $input  the validated arguments
     * @return array<string, mixed>
     */
    abstract protected function answer(array $input, McpActor $actor): array;

    /** @return array<string, bool> */
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    /**
     * The FH mark on every tool too, not only the server: a client that draws a tool call with the
     * tool's own icon then shows FH's rather than its default.
     *
     * @return list<Icon>
     */
    public function icons(): array
    {
        return McpUrl::icons();
    }

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        // No one signed in: only a client doing the handshake before it has a key (config('fhmcp.open_handshake')).
        // It sees the tool list and can run nothing: AuthenticateMcpKey refuses a key-less call.
        if (! $user instanceof User) {
            return (bool) config('fhmcp.open_handshake');
        }

        return McpRole::of($user)?->covers($this->policy()->role()) ?? false;
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $actor = McpActor::current();

        if ($actor === null) {
            return Response::error('Not allowed.');
        }

        if (! $actor->role->covers($this->policy()->role())) {
            $this->record($actor, $request->all(), 'denied', 'wrong role for this tool', $started, 0);

            return Response::error('This tool is not available to your account.');
        }

        try {
            $input = $request->validate($this->rules());
            $json = McpOutput::json($this->answer($input, $actor));
        } catch (ValidationException $e) {
            return $this->failed($actor, $request, collect($e->errors())->flatten()->first() ?? 'Those arguments are not valid.', $started);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // The caller's own mistake (a filter the metric does not take, days out of range, an answer too big).
            return $this->failed($actor, $request, $e->getMessage(), $started);
        } catch (Throwable $e) {
            report($e);

            return $this->failed($actor, $request, 'Something went wrong on our side, and nothing was changed. Try again; if it keeps happening, tell the FH team.', $started, $e::class);
        }

        $this->record($actor, $input, 'ok', null, $started, strlen($json));

        return Response::text($json);
    }

    private function failed(McpActor $actor, Request $request, string $message, int|float $started, ?string $detail = null): Response
    {
        $this->record($actor, $request->all(), 'error', $detail ?? $message, $started, 0);

        return Response::error($message);
    }

    /** @param  array<string, mixed>  $arguments */
    private function record(McpActor $actor, array $arguments, string $outcome, ?string $error, int|float $started, int $bytes): void
    {
        McpAudit::record($actor, $this->name(), $arguments, $outcome, $error, (int) round((hrtime(true) - $started) / 1e6), $bytes);
    }
}
