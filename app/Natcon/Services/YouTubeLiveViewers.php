<?php

namespace App\Natcon\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * How many people are watching a YouTube live right now — `concurrentViewers` from the Data API,
 * the figure YouTube itself shows under the player (refreshed by YouTube every 30–60s, so this is as
 * live as the number gets). Any public channel's stream: the key identifies our project, not the channel.
 *
 * Cached for 30 seconds per video, so a crowd on the page costs YouTube one request per half-minute.
 * Null when the video is not live, when the broadcaster hides the count, when no credential is set,
 * or when YouTube refuses/fails — a failure is cached too, so an outage never becomes a request storm.
 *
 * Credentials: YOUTUBE_API_KEY (a plain Data API key, Application restrictions = None), else the
 * upload feature's OAuth refresh token.
 */
class YouTubeLiveViewers
{
    private const VIDEOS_URL = 'https://www.googleapis.com/youtube/v3/videos';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function count(string $videoId): ?int
    {
        if (! preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId)) {
            return null;
        }
        // Cache::remember treats null as a miss, so a failure is stored as -1 and read back as null.
        $cached = Cache::remember("natcon:yt:viewers:{$videoId}", 30, fn () => $this->fetch($videoId) ?? -1);

        return $cached >= 0 ? (int) $cached : null;
    }

    private function fetch(string $videoId): ?int
    {
        $query = ['part' => 'liveStreamingDetails', 'id' => $videoId];
        $request = Http::timeout(8)->acceptJson();
        $key = (string) config('services.youtube.api_key', '');
        if ($key !== '') {
            $query['key'] = $key;
        } else {
            $token = $this->accessToken();
            if ($token === null) {
                return null;
            }
            $request = $request->withToken($token);
        }

        try {
            $res = $request->get(self::VIDEOS_URL, $query);
            if (! $res->ok()) {
                Log::info('natcon.live.viewers_unavailable', ['video' => $videoId, 'status' => $res->status(), 'message' => $res->json('error.message')]);

                return null;
            }
            $viewers = $res->json('items.0.liveStreamingDetails.concurrentViewers');

            return is_numeric($viewers) ? (int) $viewers : null;
        } catch (\Throwable $e) {
            Log::info('natcon.live.viewers_unavailable', ['video' => $videoId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The upload feature's OAuth token, minted from its refresh token and kept for 50 minutes. */
    private function accessToken(): ?string
    {
        $clientId = (string) config('services.youtube.client_id', '');
        $secret = (string) config('services.youtube.client_secret', '');
        $refresh = (string) config('services.youtube.refresh_token', '');
        if ($clientId === '' || $secret === '' || $refresh === '') {
            return null;
        }
        $token = Cache::remember('natcon:yt:access-token', 50 * 60, function () use ($clientId, $secret, $refresh) {
            try {
                $res = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                    'client_id' => $clientId, 'client_secret' => $secret, 'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
                ]);

                return $res->ok() ? (string) ($res->json('access_token') ?? '') : '';
            } catch (\Throwable) {
                return '';
            }
        });

        return $token !== '' ? $token : null;
    }
}
