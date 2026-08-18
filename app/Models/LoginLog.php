<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoginLog extends Model
{
    /**
     * Character width of the user_agent column.
     *
     * Kept in step with 2026_08_18_000000_widen_login_logs_user_agent.
     */
    public const USER_AGENT_MAX = 512;

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'logged_in_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a successful login. Never throws.
     *
     * ─── Why this is a helper and not four ::create() calls ──────────────────
     *
     * There are four login paths (OTP, dev, Google, password) and every one of
     * them wrote this row inline, unguarded, BEFORE returning the response. So a
     * failure here did not lose a log line — it returned a 500 from a login that
     * had already succeeded: the account was verified, the token was issued, and
     * the caller was told the login failed.
     *
     * That is exactly what happened. Facebook and Instagram in-app browsers send
     * user agents of 300–400 characters (the FBAN/FBAV/FBBV/FBDV… block), the
     * column was VARCHAR(255), and MySQL in strict mode rejects the row rather
     * than truncating it. Anyone opening the site from a Facebook link could not
     * log in at all, and would fail again on every retry, because their browser
     * sends that same agent every time.
     *
     * Both halves of the fix matter. The column is now 512, and the value is
     * truncated to fit — a widened column only moves the ceiling, and some
     * crawler will eventually send a longer one. And an audit row is worth less
     * than a login, so if the write fails anyway it is logged and swallowed.
     */
    public static function record(int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        try {
            static::create([
                'user_id'    => $userId,
                'ip_address' => $ipAddress,
                // mb_substr, not substr: the column counts CHARACTERS under
                // utf8mb4, and cutting mid-sequence would produce invalid UTF-8
                // and a different rejection.
                'user_agent' => $userAgent === null
                    ? null
                    : mb_substr($userAgent, 0, self::USER_AGENT_MAX),
                'logged_in_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('LoginLog write failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
