<?php

namespace App\Http\Controllers;

use App\Jobs\RunSeoCommand;
use App\Models\SeoCommandRun;
use App\Services\AuditSeoService;
use App\Services\Seo\SeoCommandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Command-execution lifecycle for the SEO pipeline (admin SEO Manage page):
 * list the registered commands with their last/next runs, trigger one on the
 * queue, and browse run history. Knows nothing about facilities or counts —
 * the registry is the whitelist, the run table is the state.
 */
class SeoCommandController extends Controller
{
    public function __construct(private readonly AuditSeoService $audit)
    {
    }

    /** Registry + last run + next scheduled run per command. */
    public function index(): JsonResponse
    {
        // Latest run per command in one query (small table, indexed by command).
        $latestRuns = SeoCommandRun::query()
            ->whereIn('id', function ($q) {
                $q->selectRaw('MAX(id)')
                    ->from('seo_command_runs')
                    ->groupBy('command');
            })
            ->get()
            ->keyBy('command');

        $activeCommands = SeoCommandRun::query()
            ->active()
            ->pluck('command')
            ->unique()
            ->flip();

        $commands = collect(SeoCommandRegistry::all())->map(function (array $meta, string $command) use ($latestRuns, $activeCommands) {
            $next = SeoCommandRegistry::nextRunAt($command);

            return [
                'command'     => $command,
                'label'       => $meta['label'],
                'description' => $meta['description'],
                'cron'        => $meta['cron'],
                'table'       => $meta['table'],
                'next_run_at' => $next?->toIso8601String(),
                'is_active'   => $activeCommands->has($command),
                'last_run'    => $latestRuns->get($command),
            ];
        })->values();

        return response()->json(['data' => $commands]);
    }

    /**
     * Queue a manual run. The run row is created BEFORE dispatch so the UI
     * shows "queued" instantly and has an id to poll; RunSeoCommand walks it
     * to a terminal status (and holds the real overlap guards — this check
     * is just the fast-feedback layer).
     */
    public function trigger(Request $request, string $command): JsonResponse
    {
        if (! SeoCommandRegistry::isRunnable($command)) {
            return response()->json(['message' => "Unknown command: {$command}"], 404);
        }

        if (SeoCommandRun::hasActiveRun($command)) {
            return response()->json([
                'message' => 'This command is already queued or running — check the run history.',
            ], 409);
        }

        $run = SeoCommandRun::create([
            'command'        => $command,
            'status'         => SeoCommandRun::STATUS_QUEUED,
            'trigger_source' => SeoCommandRun::SOURCE_MANUAL,
            'triggered_by'   => $request->user()->id,
            'queued_at'      => now(),
        ]);

        RunSeoCommand::dispatch($run->id)->afterCommit();

        $this->audit->recordCommandTrigger($request->user(), $command, $run);

        return response()->json(['data' => $run], 202);
    }

    /** Paginated run history, optionally filtered to one command. */
    public function runs(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = SeoCommandRun::query()->with('triggeredByUser:id,name')->latest('id');
        if (($command = (string) $request->query('command', '')) !== '') {
            $query->where('command', $command);
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /** Poll target for the Run Now button. */
    public function showRun(SeoCommandRun $run): JsonResponse
    {
        return response()->json(['data' => $run->load('triggeredByUser:id,name')]);
    }
}
