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
            [$client, $platform] = $this->resolveClientPlatform($request);

            // "Albert Bayarcal logged in on Mobile (Android) via google".
            // Web logins stay terse ("… logged in" / "… logged in via google")
            // since the existing UI doesn't badge web origin.
            $origin = '';
            if ($client === 'mobile') {
                $label = $platform === 'ios' ? 'iOS' : ($platform === 'android' ? 'Android' : null);
                $origin = ' on Mobile' . ($label ? " ({$label})" : '');
            }
            $description = $user->name . ' logged in' . $origin
                . ($via !== 'password' ? " via {$via}" : '');

            Audit::create([
                'user_id'         => $user->id,
                'user_type'       => User::class,
                'user_role'       => $user->role?->name,
                'user_name'       => $user->name,
                'event'           => 'logged_in',
                'category'        => 'auth',
                'source'          => $via,
                'client'          => $client,
                'device_platform' => $platform,
                'auditable_type'  => User::class,
                'auditable_id'    => $user->id,
                'subject_label'   => $user->name,
                'description'     => $description,
                'ip_address'      => $request?->ip(),
                'user_agent'      => $request?->userAgent(),
                'url'             => $request?->fullUrl(),
                'old_values'      => null,
                'new_values'      => null,
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

    /**
     * Best-effort origin detection for a login. Returns
     * [client, device_platform] where client ∈ {'mobile','web',null} and
     * device_platform ∈ {'android','ios','web',null}.
     *
     * Priority:
     *   1. Explicit signals the mobile app now sends (`client`, `platform`) —
     *      deterministic for updated installs.
     *   2. The `device_name` the app already attaches to every auth call
     *      (e.g. "Pixel 8 · Android", "iPhone 15 · iOS") — covers already
     *      deployed apps that predate the explicit fields.
     *   3. User-Agent fallback → a real browser means 'web'.
     */
    private function resolveClientPlatform(?Request $request): array
    {
        if (!$request) {
            return [null, null];
        }

        // 1. Explicit fields (Phase 3 mobile payload).
        $client   = strtolower(trim((string) $request->input('client', '')));
        $platform = strtolower(trim((string) $request->input('platform', '')));
        if ($client === 'mobile' || in_array($platform, ['android', 'ios'], true)) {
            $platform = in_array($platform, ['android', 'ios'], true) ? $platform : null;
            return ['mobile', $platform];
        }

        // 2. device_name the app already sends ("<model> · <OS>").
        $deviceName = strtolower((string) $request->input('device_name', ''));
        if ($deviceName !== '') {
            if (str_contains($deviceName, 'android')) {
                return ['mobile', 'android'];
            }
            if (str_contains($deviceName, 'ios') || str_contains($deviceName, 'iphone') || str_contains($deviceName, 'ipad')) {
                return ['mobile', 'ios'];
            }
            // A device_name without an OS hint still signals a native client.
            return ['mobile', null];
        }

        // 3. Browser User-Agent → web. No UA at all stays unknown (null) so we
        // don't mislabel server-to-server or test traffic.
        $ua = (string) $request->userAgent();
        if ($ua !== '' && preg_match('/Mozilla|Chrome|Safari|Firefox|Edge|Opera/i', $ua)) {
            return ['web', 'web'];
        }

        return [null, null];
    }
}
