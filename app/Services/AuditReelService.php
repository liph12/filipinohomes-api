<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes Audit rows for Reel Maker usage. The reel video is built entirely
 * client-side (canvas → MediaRecorder), so there's no model mutation to hang a
 * LogsActivity observer on — the frontend pings /reels/events on each action and
 * we record it here with a direct Audit::create, mirroring AuditMailService.
 *
 * Events: reel_opened, reel_previewed, reel_generated, reel_shared — all under
 * the 'reels' category so System Logs can filter reel activity on its own.
 */
class AuditReelService
{
    /** Bare event names accepted from the client (stored as reel_<event>). */
    public const EVENTS = ['opened', 'previewed', 'generated', 'shared'];

    public function record(string $event, ?Listing $listing, array $meta = []): void
    {
        try {
            if (!in_array($event, self::EVENTS, true)) {
                return;
            }

            $user  = Auth::user();
            $name  = $listing->name ?? 'a listing';
            $label = $listing
                ? trim(($listing->code ? "{$listing->code} — " : '') . ($listing->name ?? 'Listing'))
                : 'Listing';

            Audit::create([
                'user_id'        => $user?->id,
                'user_type'      => $user ? User::class : null,
                'user_role'      => $user?->role?->name,
                'user_name'      => $user?->name,
                'event'          => "reel_{$event}",
                'category'       => 'reels',
                'source'         => 'reel_maker',
                'auditable_type' => $listing ? Listing::class : null,
                'auditable_id'   => $listing?->id,
                'subject_label'  => $label,
                'description'    => $this->describe($event, $user?->name, $name),
                'old_values'     => null,
                'new_values'     => $meta ?: null,
            ]);
        } catch (Throwable $e) {
            // Usage tracking must never break the reel itself — log and move on.
            Log::warning('Reel audit write failed', ['error' => $e->getMessage()]);
        }
    }

    private function describe(string $event, ?string $user, string $listing): string
    {
        $who = $user ?? 'Someone';

        return match ($event) {
            'opened'    => "{$who} opened the Reel Maker for {$listing}",
            'previewed' => "{$who} previewed a reel for {$listing}",
            'generated' => "{$who} generated a reel for {$listing}",
            'shared'    => "{$who} shared a reel for {$listing}",
            default     => "{$who} used the Reel Maker for {$listing}",
        };
    }
}
