<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\User;
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
        AppNotification::create([
            'user_id' => $user->id,
            'type'    => $type,
            'title'   => $title,
            'body'    => $body,
            'data'    => $data,
        ]);

        $tokens = DeviceToken::where('user_id', $user->id)
            ->pluck('expo_token')
            ->all();

        if (empty($tokens)) {
            return;
        }

        $this->send($tokens, $title, $body, $data);
    }

    /**
     * POST the messages to Expo in chunks of 100 and prune any token Expo
     * reports as DeviceNotRegistered.
     *
     * @param  list<string>  $tokens
     * @param  array<string,mixed>  $data
     */
    private function send(array $tokens, string $title, string $body, array $data): void
    {
        $accessToken = config('services.expo.access_token');

        foreach (array_chunk($tokens, 100) as $chunk) {
            $messages = array_map(fn ($token) => [
                'to'        => $token,
                'title'     => $title,
                'body'      => $body,
                'data'      => $data,
                'sound'     => 'default',
                // High priority + explicit channel so Android delivers
                // immediately instead of deferring under Doze/battery saver
                // until the app is next opened.
                'priority'  => 'high',
                'channelId' => 'default',
            ], $chunk);

            try {
                $request = Http::asJson()->acceptJson();
                if ($accessToken) {
                    $request = $request->withToken($accessToken);
                }

                $response = $request->post(self::ENDPOINT, $messages);

                if (!$response->successful()) {
                    Log::warning('Expo push request failed', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    continue;
                }

                $this->pruneDeadTokens($chunk, $response->json('data') ?? []);
            } catch (\Throwable $e) {
                Log::warning('Expo push exception', ['error' => $e->getMessage()]);
            }
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

        if (!empty($dead)) {
            DeviceToken::whereIn('expo_token', $dead)->delete();
        }
    }
}
