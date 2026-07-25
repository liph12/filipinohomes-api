<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\SeoCommandRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes Audit rows for SEO-admin actions that aren't model mutations and
 * therefore can't go through the LogsActivity trait (facility CRUD IS a
 * model mutation — the Facility model's trait covers it). Currently:
 *
 *   - seo_command_triggered — an admin pressed "Run now" on a pipeline command
 *
 * Deliberately audits the HUMAN action once, not the run row's status flips
 * (that would spam the feed — the run history lives in seo_command_runs).
 *
 * Mirrors the defensive pattern of AuditSecurityService: every write wrapped
 * in try/catch so a bookkeeping miss never propagates into the trigger flow.
 * Rows land under the `seo` category (registered in
 * ActivityLogController::CATEGORIES).
 */
class AuditSeoService
{
    public function recordCommandTrigger(User $user, string $command, SeoCommandRun $run): void
    {
        try {
            Audit::create([
                'user_id'        => $user->id,
                'user_type'      => User::class,
                'user_role'      => $user->role?->name,
                'user_name'      => $user->name,
                'event'          => 'seo_command_triggered',
                'category'       => 'seo',
                'source'         => 'seo_admin',
                'auditable_type' => SeoCommandRun::class,
                'auditable_id'   => $run->id,
                'subject_label'  => $command,
                'description'    => "{$user->name} triggered {$command}",
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
                'url'            => request()?->fullUrl(),
                'old_values'     => null,
                'new_values'     => [
                    'command' => $command,
                    'run_id'  => $run->id,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('SEO audit (seo_command_triggered) write failed', [
                'user_id' => $user->id,
                'command' => $command,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
