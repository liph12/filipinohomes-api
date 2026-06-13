<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications through the Expo Push Service and records them in
 * the in-app feed (app_notifications). One user can have many device tokens
 * (one per device); every token is notified and dead tokens are pruned.
 */
class ExpoPushService
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    private const MESSAGES_CHANNEL = 'messages';

    /**
     * Persist an in-app notification row and push to every device the user
     * owns. Non-fatal: a delivery failure logs a warning, it never throws into
     * the caller (message-send / audit flows must not roll back on push error).
     *
     * @param  array<string,mixed>  $data  Deep-link payload, e.g. ['type' => 'inquiry', 'id' => 12]
     */
    public function notify(User $user, string $type, string $title, string $body, array $data = []): void
    {
        // Always record the in-app feed row even if the user has no device
        // registered yet — the Notifications screen still shows it.
        $notification = AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        // Carry the row id so a deep link (e.g. listing_inquiry →
        // notification/{id}) can load the detail on a cold push tap. Additive;
        // callers that route on data.id are unaffected.
        $data['notification_id'] = $notification->id;

        // Category toggle (Settings): the feed row above is always recorded;
        // only the push is muted when the user has this category turned off.
        if (! $user->allowsPushCategory($type)) {
            return;
        }

        $tokens = DeviceToken::where('user_id', $user->id)
            ->pluck('expo_token')
            ->all();

        if (empty($tokens)) {
            return;
        }

        $this->send($tokens, $title, $body, $data);
    }

    /**
     * Like notify(), but for chat messages: sends a standard title/body push
     * on the dedicated "messages" channel and still carries the rich data
     * payload (thread_key, sender_avatar, re_label…) so the app can upgrade it
     * to a Messenger-style notification in the foreground via Notifee.
     *
     * We deliberately do NOT send a data-only push to Android: that relies on
     * the app waking in the background to render the notification itself, which
     * Android (especially battery-restricted OEM ROMs) does unreliably — the
     * result was no notification at all while backgrounded/locked. A normal
     * title/body push is drawn by the OS, so it always arrives.
     * The in-app feed row is recorded once, exactly like notify().
     *
     * @param  array<string,mixed>  $data  Rich payload incl. thread_key, sender_name, sender_avatar, re_label, body…
     */
    public function notifyMessage(User $user, string $title, string $body, array $data = []): void
    {
        $type = $data['type'] ?? 'inquiry';

        AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        // Category toggle: keep the feed row, mute only the push.
        if (! $user->allowsPushCategory($type)) {
            return;
        }

        $tokens = DeviceToken::where('user_id', $user->id)->pluck('expo_token')->all();
        if (empty($tokens)) {
            return;
        }

        $this->send($tokens, $title, $body, $data, self::MESSAGES_CHANNEL);
    }

    /**
     * Broadcast an admin announcement to many users at once. Fans out one
     * app_notifications feed row per recipient (tagged with announcement_id so
     * the analytics screen can count reads), then pushes to every matching
     * device token. Rows are bulk-inserted, so the data payload is encoded
     * here rather than going through the model's array cast.
     *
     * @param  Collection<int,User>  $users     Recipients (each needs an id).
     * @param  array<string,mixed>   $data       Push payload (deep-link etc.).
     * @param  string|null           $platform   Restrict pushes to this platform.
     * @return int  Number of recipients notified.
     */
    public function broadcast(Collection $users, string $kind, string $title, string $body, array $data, int $announcementId, ?string $platform = null): int
    {
        if ($users->isEmpty()) {
            return 0;
        }

        $now = now();
        $encodedData = json_encode($data);

        $rows = $users->map(fn ($user) => [
            'user_id' => $user->id,
            'announcement_id' => $announcementId,
            'type' => $kind,
            'title' => $title,
            'body' => $body,
            'data' => $encodedData,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            AppNotification::insert($chunk);
        }

        $tokenQuery = DeviceToken::whereIn('user_id', $users->pluck('id'));
        if ($platform) {
            $tokenQuery->where('platform', $platform);
        }
        $tokens = $tokenQuery->pluck('expo_token')->all();

        if (! empty($tokens)) {
            $this->send($tokens, $title, $body, $data);
        }

        return $users->count();
    }

    /**
     * POST the messages to Expo in chunks of 100 and prune any token Expo
     * reports as DeviceNotRegistered.
     *
     * @param  list<string>  $tokens
     * @param  array<string,mixed>  $data
     */
    private function send(array $tokens, string $title, string $body, array $data, string $channelId = 'default'): void
    {
        foreach (array_chunk($tokens, 100) as $chunk) {
            $messages = array_map(fn ($token) => [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => 'default',
                // High priority + explicit channel so Android delivers
                // immediately instead of deferring under Doze/battery saver
                // until the app is next opened.
                'priority' => 'high',
                'channelId' => $channelId,
            ], $chunk);

            $this->postMessages($messages, $chunk);
        }
    }

    /**
     * Send one chunk of Expo messages and prune dead tokens. Shared by the
     * plain and data-only senders.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  list<string>  $chunk
     */
    private function postMessages(array $messages, array $chunk): void
    {
        $accessToken = config('services.expo.access_token');

        try {
            $request = Http::asJson()->acceptJson();
            if ($accessToken) {
                $request = $request->withToken($accessToken);
            }

            $response = $request->post(self::ENDPOINT, $messages);

            if (! $response->successful()) {
                // Expo rejects the whole request with PUSH_TOO_MANY_EXPERIENCE_IDS
                // when a batch mixes tokens from more than one Expo project — e.g.
                // a leftover dev-build token (@filipinohomes-dev/...) sitting in the
                // table next to production tokens (@johnmaizo/filipinohomes-app).
                // Re-send each message on its own so the valid production tokens
                // still get delivered instead of the whole batch being dropped.
                if (count($messages) > 1 && str_contains($response->body(), 'PUSH_TOO_MANY_EXPERIENCE_IDS')) {
                    foreach ($messages as $i => $message) {
                        $this->postMessages([$message], [$chunk[$i]]);
                    }

                    return;
                }

                Log::warning('Expo push request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            $this->pruneDeadTokens($chunk, $response->json('data') ?? []);
        } catch (\Throwable $e) {
            Log::warning('Expo push exception', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Expo returns one receipt per message in order. A receipt with
     * status=error and details.error=DeviceNotRegistered means the token is
     * dead (app uninstalled / token rotated) — delete it so we stop trying.
     *
     * @param  list<string>  $chunk
     * @param  array<int,array<string,mixed>>  $receipts
     */
    private function pruneDeadTokens(array $chunk, array $receipts): void
    {
        $dead = [];
        foreach ($receipts as $i => $receipt) {
            $isError = ($receipt['status'] ?? null) === 'error';
            $reason = $receipt['details']['error'] ?? null;
            if ($isError && $reason === 'DeviceNotRegistered' && isset($chunk[$i])) {
                $dead[] = $chunk[$i];
            }
        }

        if (! empty($dead)) {
            DeviceToken::whereIn('expo_token', $dead)->delete();
        }
    }
}
