<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\YouTube\ListingVideoComposer;
use App\Services\YouTube\ListingVideoMetadata;
use App\Services\YouTube\YouTubeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Automated YouTube listing videos (see plan: OnePropertee-style pipeline,
 * curated + quality-gated). Runs on the scheduler — NOT the queue — because
 * prod has no queue worker; a scheduled command also gives us natural pacing
 * against the YouTube quota.
 *
 * Eligibility gate: public + active + not flagged + ≥5 photos + a real
 * description + never uploaded before. 'failed' listings are skipped so one
 * broken listing can't wedge the daily budget; clear youtube_video_status to
 * retry one manually.
 */
class ProcessYoutubeUploads extends Command
{
    protected $signature = 'youtube:process-uploads
        {--dry-run : Render the MP4 but skip the upload and DB write; keeps the work dir for inspection}
        {--limit=1 : Max listings to process this run}
        {--listing= : Force a specific listing id (bypasses ordering, not the quality gate)}';

    protected $description = 'Render + upload YouTube slideshow videos for eligible public listings';

    private const MIN_PHOTOS = 5;
    private const MIN_DESCRIPTION_CHARS = 40;

    public function handle(ListingVideoComposer $composer, YouTubeService $youtube): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !config('services.youtube.enabled')) {
            $this->line('YouTube uploads disabled (YOUTUBE_UPLOADS_ENABLED=false) — nothing to do.');

            return self::SUCCESS;
        }
        if (!$dryRun && !$youtube->isConfigured()) {
            $this->error('YouTube credentials missing (YOUTUBE_CLIENT_ID/SECRET/REFRESH_TOKEN).');

            return self::FAILURE;
        }

        // Daily quota guard — uploads today vs the configured cap.
        $cap = (int) config('services.youtube.daily_upload_cap', 6);
        $today = Listing::where('youtube_video_uploaded_at', '>=', now()->startOfDay())->count();
        $budget = $dryRun ? (int) $this->option('limit') : min((int) $this->option('limit'), max(0, $cap - $today));
        if ($budget <= 0) {
            $this->line("Daily upload cap reached ({$today}/{$cap}).");

            return self::SUCCESS;
        }

        $candidates = $this->eligibleListings($budget);
        if ($candidates->isEmpty()) {
            $this->line('No eligible listings.');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($candidates as $listing) {
            if ($done >= $budget) {
                break;
            }
            $this->info("Processing listing {$listing->id} — {$listing->name}");

            try {
                $video = $composer->compose($listing);
                $meta = new ListingVideoMetadata($listing);

                if ($dryRun) {
                    $this->line('  [dry-run] MP4: ' . $video['mp4']);
                    $this->line('  [dry-run] Title: ' . $meta->title());
                    $this->line('  [dry-run] Description:');
                    $this->line($meta->description());
                    $this->line('  [dry-run] Caption track (SRT):');
                    $this->line($meta->captionSrt($video['duration']));
                    $this->line('  [dry-run] Work dir kept for inspection.');
                    $done++;

                    continue;
                }

                $videoId = $youtube->uploadVideo(
                    $video['mp4'],
                    $meta->title(),
                    $meta->description(),
                    $meta->tags(),
                );
                $youtube->setThumbnail($videoId, $video['thumbnail']);
                // Auto-generated transcript — indexed by YouTube search.
                // Needs the youtube.force-ssl scope on the refresh token.
                $youtube->uploadCaption($videoId, $meta->captionSrt($video['duration']));

                // saveQuietly: skip model events — no IndexNow ping / audit
                // noise for a metadata-only side-channel write.
                $listing->youtube_video_id = $videoId;
                $listing->youtube_video_status = 'uploaded';
                $listing->youtube_video_uploaded_at = now();
                $listing->saveQuietly();

                $composer->cleanup($video['work_dir']);
                $this->info("  Uploaded: https://youtu.be/{$videoId}");
                $done++;
            } catch (\Throwable $e) {
                Log::error('YouTube listing video failed', [
                    'listing_id' => $listing->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error('  Failed: ' . $e->getMessage());

                if (!$dryRun) {
                    $listing->youtube_video_status = 'failed';
                    $listing->saveQuietly();
                }
            }
        }

        $this->line("Done — {$done} video(s) processed.");

        return self::SUCCESS;
    }

    /**
     * Newest-first eligible listings. The SQL narrows cheaply; the photo and
     * description quality gates run in PHP on a small buffered page (JSON
     * column lengths aren't worth an index).
     */
    private function eligibleListings(int $budget)
    {
        $query = Listing::query()
            ->where('visibility', 'public')
            ->whereNull('youtube_video_id')
            ->whereNull('youtube_video_status')
            ->where(fn ($q) => $q->whereNull('verification_status')->orWhere('verification_status', '!=', 'flagged'))
            ->whereHas('property', fn ($q) => $q->where('status', 'active'))
            ->with([
                'property.propertyAttribute.subtype.type',
                'property.barangay.city.province',
                'category',
                'agent',
            ]);

        if ($id = $this->option('listing')) {
            $query->where('id', (int) $id);
        } else {
            $query->latest();
        }

        return $query
            ->limit(max($budget * 5, 10))
            ->get()
            ->filter(function (Listing $l) {
                $photoCount = count(array_filter((array) ($l->property?->photos ?? [])));
                $description = trim(strip_tags((string) ($l->property?->description ?? '')));

                return $photoCount >= self::MIN_PHOTOS
                    && mb_strlen($description) >= self::MIN_DESCRIPTION_CHARS;
            })
            ->take($budget * 2) // small retry headroom within one run
            ->values();
    }
}
