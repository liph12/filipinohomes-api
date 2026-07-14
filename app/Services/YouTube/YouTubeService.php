<?php

namespace App\Services\YouTube;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin YouTube Data API v3 client for the brand channel, using the plain
 * Laravel Http facade (same pattern as GoogleTokenService / IndexNowService)
 * instead of the heavyweight google/apiclient package — we only need three
 * calls: OAuth token refresh, resumable videos.insert, and thumbnails.set.
 *
 * Auth model: a single long-lived OAuth REFRESH TOKEN for the FilipinoHomes
 * channel, minted once by the team (see the plan's Phase 0) and stored in env
 * (services.youtube.*). Access tokens are minted per run and not persisted.
 *
 * Quota notes (verified 2026-07): videos.insert historically costs 1,600 of
 * the default 10,000 daily units (~6 uploads/day); the Dec-2025 revision
 * allots ~100 insert calls/day. The daily_upload_cap config stays the
 * authoritative throttle either way.
 */
class YouTubeService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';
    private const THUMBNAIL_URL = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';
    private const CAPTIONS_URL = 'https://www.googleapis.com/upload/youtube/v3/captions';

    private ?string $accessToken = null;

    public function isConfigured(): bool
    {
        return (bool) (config('services.youtube.client_id')
            && config('services.youtube.client_secret')
            && config('services.youtube.refresh_token'));
    }

    /**
     * Exchange the stored refresh token for a short-lived access token.
     * Cached for the lifetime of this service instance (one command run).
     */
    private function accessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $res = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'refresh_token' => config('services.youtube.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (!$res->ok() || !$res->json('access_token')) {
            throw new RuntimeException(
                'YouTube OAuth token refresh failed: ' . $res->status() . ' ' . $res->body()
            );
        }

        return $this->accessToken = $res->json('access_token');
    }

    /**
     * Upload a video via the resumable protocol and return the YouTube
     * video id.
     *
     * @param string   $filePath    local MP4 path
     * @param string   $title       <= 100 chars
     * @param string   $description <= 5000 bytes
     * @param string[] $tags
     */
    public function uploadVideo(string $filePath, string $title, string $description, array $tags = []): string
    {
        $size = filesize($filePath);
        if (!$size) {
            throw new RuntimeException("Video file missing or empty: {$filePath}");
        }

        $metadata = [
            'snippet' => [
                'title' => mb_substr($title, 0, 100),
                'description' => $description,
                'tags' => array_slice($tags, 0, 30),
                // 22 = People & Blogs — the conventional bucket for listing
                // slideshows (matches what property portals use).
                'categoryId' => '22',
            ],
            'status' => [
                'privacyStatus' => config('services.youtube.privacy_status', 'public'),
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        // Step 1 — open a resumable session; YouTube answers with the
        // one-time upload URL in the Location header.
        $start = Http::withToken($this->accessToken())
            ->withHeaders([
                'X-Upload-Content-Type' => 'video/mp4',
                'X-Upload-Content-Length' => (string) $size,
            ])
            ->timeout(30)
            ->post(self::UPLOAD_URL . '?uploadType=resumable&part=snippet,status', $metadata);

        $uploadUrl = $start->header('Location');
        if (!$start->successful() || !$uploadUrl) {
            throw new RuntimeException(
                'YouTube resumable session failed: ' . $start->status() . ' ' . $start->body()
            );
        }

        // Step 2 — PUT the bytes. A ~25 MB 720p slideshow fits comfortably in
        // memory; if outputs ever grow past ~100 MB switch to chunked PUTs.
        $put = Http::withToken($this->accessToken())
            ->withBody(file_get_contents($filePath), 'video/mp4')
            ->timeout(600)
            ->put($uploadUrl);

        $videoId = $put->json('id');
        if (!$put->successful() || !$videoId) {
            throw new RuntimeException(
                'YouTube video upload failed: ' . $put->status() . ' ' . $put->body()
            );
        }

        return $videoId;
    }

    /**
     * Upload an auto-generated SRT caption track (the video's "transcript" —
     * caption text is indexed by YouTube search, which a music-only slideshow
     * otherwise has no words for). Requires the youtube.force-ssl scope on
     * the refresh token; costs ~400 quota units. Non-fatal like the
     * thumbnail — the video is already live if this fails.
     */
    public function uploadCaption(string $videoId, string $srt, string $language = 'en'): bool
    {
        try {
            $metadata = [
                'snippet' => [
                    'videoId' => $videoId,
                    'language' => $language,
                    'name' => '', // default track — player shows plain "English"
                    'isDraft' => false,
                ],
            ];

            $start = Http::withToken($this->accessToken())
                ->withHeaders([
                    'X-Upload-Content-Type' => 'application/octet-stream',
                    'X-Upload-Content-Length' => (string) strlen($srt),
                ])
                ->timeout(30)
                ->post(self::CAPTIONS_URL . '?uploadType=resumable&part=snippet', $metadata);

            $uploadUrl = $start->header('Location');
            if (!$start->successful() || !$uploadUrl) {
                Log::warning('YouTube captions.insert session failed', [
                    'video_id' => $videoId,
                    'status' => $start->status(),
                    'body' => mb_substr($start->body(), 0, 500),
                ]);

                return false;
            }

            $put = Http::withToken($this->accessToken())
                ->withBody($srt, 'application/octet-stream')
                ->timeout(60)
                ->put($uploadUrl);

            if (!$put->successful()) {
                Log::warning('YouTube captions.insert upload failed', [
                    'video_id' => $videoId,
                    'status' => $put->status(),
                    'body' => mb_substr($put->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('YouTube captions.insert threw', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Set the custom thumbnail (requires a phone-verified channel).
     * Non-fatal by design — callers treat a failure as a warning, the video
     * itself is already live with YouTube's auto-generated thumbnail.
     */
    public function setThumbnail(string $videoId, string $pngPath): bool
    {
        try {
            $res = Http::withToken($this->accessToken())
                ->withBody(file_get_contents($pngPath), 'image/png')
                ->timeout(60)
                ->post(self::THUMBNAIL_URL . '?videoId=' . urlencode($videoId));

            if (!$res->successful()) {
                Log::warning('YouTube thumbnails.set failed', [
                    'video_id' => $videoId,
                    'status' => $res->status(),
                    'body' => mb_substr($res->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('YouTube thumbnails.set threw', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
