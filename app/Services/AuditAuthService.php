<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Writes Audit rows for authentication events (login, eventually
 * logout / password changes if we extend). Login is the first
 * case — today logins live only in `login_logs` and never surface
 * on /admin/activity-logs. This service writes an Audit row in
 * parallel so the admin activity feed includes them.
 *
 * Direct Audit::create — NOT via the LogsActivity trait — because
 * logging in isn't a model mutation; there's nothing to diff.
 */
class AuditAuthService
{
    /**
     * Record a successful authentication.
     *
     * @param  string  $via  e.g. 'password' | 'google' | 'otp' | 'dev'
     */
    public function recordLogin(User $user, string $via, ?Request $request = null): void
    {
        try {
            $description = $user->name . ' logged in'
                . ($via !== 'password' ? " via {$via}" : '');

            Audit::create([
                'user_id'        => $user->id,
                'user_type'      => User::class,
                'user_role'      => $user->role?->name,
                'user_name'      => $user->name,
                'event'          => 'logged_in',
                'category'       => 'auth',
                'source'         => $via,
                'auditable_type' => User::class,
                'auditable_id'   => $user->id,
                'subject_label'  => $user->name,
                'description'    => $description,
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request?->userAgent(),
                'url'            => $request?->fullUrl(),
                'old_values'     => null,
                'new_values'     => null,
            ]);
        } catch (Throwable $e) {
            // Auditing must never block the auth flow itself. The
            // login already succeeded by the time we get here.
            \Illuminate\Support\Facades\Log::warning('Auth audit write failed', [
                'user_id' => $user->id,
                'via'     => $via,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
