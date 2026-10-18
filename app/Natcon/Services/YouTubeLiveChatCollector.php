<?php

namespace App\Natcon\Services;

use App\Natcon\Models\NatconEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a YouTube live stream's chat through the Data API and stores every message in
 * natcon_live_comments — the commenter list behind the live-page raffle.
 *
 * Works for ANY public channel's stream: the credential identifies OUR Google project to YouTube,
 * not the channel. Two calls: videos.list → the stream's activeLiveChatId (present only while live);
 * then liveChatMessages.list page after page, carrying the nextPageToken between runs in the cache
 * so a message is fetched once. YouTube says how long to wait between pages (pollingIntervalMillis).
 *
 * Credentials: YOUTUBE_API_KEY (a plain Data API key), else the upload feature's OAuth refresh token.
 * With neither, collect() reports 'no_credentials' and stores nothing.
 */
class YouTubeLiveChatCollector
{
    private const VIDEOS_URL = 'https://www.googleapis.com/youtube/v3/videos';

    private const CHAT_URL = 'https://www.googleapis.com/youtube/v3/liveChat/messages';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * One page of chat for this stream, stored. Returns what happened and how long YouTube asks us
     * to wait before the next page.
     *
     * @return array{status: 'stored'|'not_live'|'chat_unavailable'|'no_credentials'|'credential_rejected'|'error', inserted: int, wait_ms: int, message?: string}
     */
    public function collect(NatconEvent $event, string $videoId): array
    {
        $client = $this->client();
        if ($client === null) {
            return ['status' => 'no_credentials', 'inserted' => 0, 'wait_ms' => 60_000];
        }

        $lookup = $this->liveChatId($client, $videoId);
        if ($lookup['status'] !== 'ok') {
            return ['status' => $lookup['status'], 'inserted' => 0, 'wait_ms' => 60_000, 'message' => $lookup['message'] ?? ''];
        }
        $chatId = $lookup['chat_id'];

        $pageKey = "natcon:ytchat:{$videoId}:page";
        $query = ['liveChatId' => $chatId, 'part' => 'snippet,authorDetails', 'maxResults' => 2000];
        $page = Cache::get($pageKey);
        if (is_string($page) && $page !== '') {
            $query['pageToken'] = $page;
        }

        try {
            $res = $client->get(self::CHAT_URL, $this->withAuth($query));
        } catch (\Throwable $e) {
            Log::info('natcon.live.chat_error', ['video' => $videoId, 'error' => $e->getMessage()]);

            return ['status' => 'error', 'inserted' => 0, 'wait_ms' => 15_000];
        }
        if (! $res->ok()) {
            // 403 liveChatEnded / liveChatDisabled, 404 — the chat is gone or off; nothing more to read.
            Log::info('natcon.live.chat_unavailable', ['video' => $videoId, 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
            if (in_array($res->status(), [403, 404], true)) {
                Cache::forget("natcon:ytchat:{$videoId}:id");
            }

            return ['status' => 'chat_unavailable', 'inserted' => 0, 'wait_ms' => 60_000];
        }

        $rows = [];
        $now = Carbon::now();
        foreach ((array) $res->json('items', []) as $item) {
            $snippet = $item['snippet'] ?? [];
            $author = $item['authorDetails'] ?? [];
            $text = (string) ($snippet['displayMessage'] ?? ($snippet['textMessageDetails']['messageText'] ?? ''));
            if (empty($item['id']) || empty($author['channelId'])) {
                continue;
            }
            $rows[] = [
                'natcon_event_id' => $event->id,
                'video_id' => $videoId,
                'message_id' => (string) $item['id'],
                'channel_id' => (string) $author['channelId'],
                'display_name' => mb_substr((string) ($author['displayName'] ?? 'YouTube user'), 0, 191),
                'avatar_url' => isset($author['profileImageUrl']) ? mb_substr((string) $author['profileImageUrl'], 0, 2048) : null,
                'text' => $text,
                'published_at' => isset($snippet['publishedAt']) ? Carbon::parse($snippet['publishedAt']) : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $inserted = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            // insertOrIgnore: message_id is unique, so a page served twice stores nothing twice.
            $inserted += DB::table('natcon_live_comments')->insertOrIgnore($chunk);
        }

        $next = (string) ($res->json('nextPageToken') ?? '');
        Cache::put($pageKey, $next, now()->addDay());
        $wait = (int) ($res->json('pollingIntervalMillis') ?? 5000);

        return ['status' => 'stored', 'inserted' => $inserted, 'wait_ms' => max(2000, $wait)];
    }

    /**
     * The stream's live chat id — present only while the broadcast is live and its chat is on.
     * Tells a refused credential (a key locked to HTTP referrers, a disabled API, an exhausted quota)
     * apart from "not live", because the two need different fixes. Cached for a minute either way.
     *
     * @return array{status: 'ok'|'not_live'|'credential_rejected'|'error', chat_id?: string, message?: string}
     */
    private function liveChatId(PendingRequest $client, string $videoId): array
    {
        return Cache::remember("natcon:ytchat:{$videoId}:id", 60, function () use ($client, $videoId) {
            try {
                $res = $client->get(self::VIDEOS_URL, $this->withAuth(['part' => 'liveStreamingDetails', 'id' => $videoId]));
            } catch (\Throwable $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }
            if (! $res->ok()) {
                $reason = (string) ($res->json('error.errors.0.reason') ?? '');
                $message = (string) ($res->json('error.message') ?? "HTTP {$res->status()}");
                Log::info('natcon.live.chat_lookup_failed', ['video' => $videoId, 'status' => $res->status(), 'reason' => $reason, 'message' => $message]);

                return ['status' => in_array($res->status(), [400, 401, 403], true) ? 'credential_rejected' : 'error', 'message' => $message];
            }
            $id = $res->json('items.0.liveStreamingDetails.activeLiveChatId');

            return is_string($id) && $id !== '' ? ['status' => 'ok', 'chat_id' => $id] : ['status' => 'not_live'];
        });
    }

    private ?string $token = null;

    private bool $useKey = false;

    private function client(): ?PendingRequest
    {
        $key = (string) config('services.youtube.api_key', '');
        if ($key !== '') {
            $this->useKey = true;

            return Http::timeout(10)->acceptJson();
        }
        $this->token = $this->accessToken();

        return $this->token === null ? null : Http::timeout(10)->acceptJson()->withToken($this->token);
    }

    private function withAuth(array $query): array
    {
        return $this->useKey ? $query + ['key' => (string) config('services.youtube.api_key')] : $query;
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
