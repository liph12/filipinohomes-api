<?php

namespace App\Services\YouTube;

use App\Models\Listing;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Renders a listing's landscape (1280×720) slideshow MP4 with ffmpeg:
 *
 *   intro card (2.5s) → up to 6 photos (3.5s each, Ken Burns on landscape
 *   shots, blur-pad on portrait shots) → QR end-card (4s), 0.5s crossfades,
 *   with an in-house FH jingle underneath (safe-only music policy — the
 *   undocumented "mood" tracks are deliberately NOT used on YouTube).
 *
 * The intro card, end-card (with QR), and upload thumbnail are all rendered
 * by the Next.js /og/listing-video route — one brand template, one source of
 * truth for fonts/layout — and fetched here as PNGs. ffmpeg only assembles.
 *
 * Runs under `nice` so a render can never starve the API workers on the same
 * box (~30–60s per video at 720p).
 */
class ListingVideoComposer
{
    private const W = 1280;
    private const H = 720;
    private const FPS = 30;
    private const INTRO_SECS = 2.5;
    private const PHOTO_SECS = 3.5;
    private const OUTRO_SECS = 4.0;
    private const FADE_SECS = 0.5;
    private const MAX_PHOTOS = 6;
    private const MIN_PHOTOS = 3;
    private const CARD_BG = '#152A4E'; // navy — matches the OG card theme

    /**
     * In-house jingles only (public assets on the frontend). Rotated by
     * listing id for variety; cached locally after first fetch.
     */
    private const JINGLES = [
        'filipinohomes-jingle.mp3',
        'homes-ph-jingle.mp3',
        'fh-global-partners-jingle.mp3',
        'rent-ph-jingle.mp3',
    ];

    /**
     * Compose the video + thumbnail for a listing.
     *
     * @return array{mp4: string, thumbnail: string, work_dir: string, duration: float}
     */
    public function compose(Listing $listing): array
    {
        $workDir = storage_path('app/youtube-videos/' . $listing->id . '-' . bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workDir);

        try {
            $photos = $this->downloadPhotos($listing, $workDir);
            $intro = $this->fetchCard($listing, 'card', $workDir . '/intro.png');
            $end = $this->fetchCard($listing, 'end', $workDir . '/end.png');
            // The upload thumbnail is the same 1280×720 intro card.
            $thumbnail = $workDir . '/thumbnail.png';
            File::copy($intro, $thumbnail);

            $jingle = $this->jinglePath($listing);
            $out = $workDir . '/listing-' . $listing->id . '.mp4';

            [$cmd, $duration] = $this->buildFfmpegCommand($intro, $photos, $end, $jingle, $out);
            $this->run($cmd, $workDir);

            if (!is_file($out) || filesize($out) < 100_000) {
                throw new RuntimeException('ffmpeg produced no/empty output for listing ' . $listing->id);
            }

            return ['mp4' => $out, 'thumbnail' => $thumbnail, 'work_dir' => $workDir, 'duration' => $duration];
        } catch (\Throwable $e) {
            File::deleteDirectory($workDir);
            throw $e;
        }
    }

    public function cleanup(string $workDir): void
    {
        File::deleteDirectory($workDir);
    }

    /**
     * Download up to MAX_PHOTOS gallery photos from S3. Failed downloads are
     * skipped; fewer than MIN_PHOTOS usable photos aborts (a 2-photo
     * "slideshow" reads as spam, not marketing).
     *
     * @return array<int, array{path: string, portrait: bool}>
     */
    private function downloadPhotos(Listing $listing, string $workDir): array
    {
        $urls = array_values(array_filter((array) ($listing->property?->photos ?? [])));
        $photos = [];

        foreach (array_slice($urls, 0, self::MAX_PHOTOS) as $i => $url) {
            try {
                $res = Http::timeout(20)->get($url);
                if (!$res->ok() || strlen($res->body()) < 5_000) {
                    continue;
                }
                $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
                $path = $workDir . "/photo-{$i}.{$ext}";
                File::put($path, $res->body());

                $size = @getimagesize($path);
                if (!$size || $size[0] < 320 || $size[1] < 320) {
                    continue;
                }
                $photos[] = ['path' => $path, 'portrait' => $size[1] > $size[0]];
            } catch (\Throwable) {
                continue;
            }
        }

        if (count($photos) < self::MIN_PHOTOS) {
            throw new RuntimeException(
                'Listing ' . $listing->id . ' has only ' . count($photos) . ' usable photos (need ≥' . self::MIN_PHOTOS . ')'
            );
        }

        return $photos;
    }

    /** Fetch the branded intro/end card PNG from the Next.js OG route. */
    private function fetchCard(Listing $listing, string $mode, string $dest): string
    {
        $attr = $listing->property?->propertyAttribute;
        $site = rtrim(config('services.youtube.site_url', 'https://filipinohomes.com'), '/');
        $base = rtrim(config('services.youtube.og_base', $site), '/');

        $params = array_filter([
            'mode' => $mode,
            'title' => $listing->name,
            'loc' => $listing->property?->address,
            'price' => $listing->price > 0 ? '₱' . number_format((float) $listing->price) : null,
            'cat' => $listing->category?->name,
            'type' => $attr?->subtype?->name ?? $attr?->subtype?->type?->name,
            'beds' => $attr?->bedroom_count ?: null,
            'baths' => $attr?->bathroom_count ?: null,
            'area' => (float) ($attr?->floor_area ?? 0) > 0 ? (string) $attr->floor_area : null,
            'code' => $listing->code,
            'photo' => is_array($listing->featured_photo) ? ($listing->featured_photo[0] ?? null) : $listing->featured_photo,
            // End-card QR target — the canonical listing URL.
            'url' => $mode === 'end' ? $site . '/' . $listing->slug : null,
        ], fn ($v) => $v !== null && $v !== '');

        $res = Http::timeout(30)->get($base . '/og/listing-video', $params);
        if (!$res->ok() || !str_starts_with($res->header('Content-Type') ?? '', 'image/')) {
            throw new RuntimeException("Card render ({$mode}) failed for listing {$listing->id}: HTTP " . $res->status());
        }
        File::put($dest, $res->body());

        return $dest;
    }

    /** Resolve (and cache) the jingle for this listing — rotate by id. */
    private function jinglePath(Listing $listing): string
    {
        $file = self::JINGLES[$listing->id % count(self::JINGLES)];
        $cacheDir = storage_path('app/youtube-assets');
        File::ensureDirectoryExists($cacheDir);
        $path = $cacheDir . '/' . $file;

        if (!is_file($path) || filesize($path) < 10_000) {
            $site = rtrim(config('services.youtube.site_url', 'https://filipinohomes.com'), '/');
            $res = Http::timeout(30)->get($site . '/reel-audio/' . $file);
            if (!$res->ok()) {
                throw new RuntimeException("Jingle fetch failed: {$file} (HTTP {$res->status()})");
            }
            File::put($path, $res->body());
        }

        return $path;
    }

    /**
     * Assemble the single-invocation ffmpeg argv: per-segment normalize
     * filters → xfade chain → looped jingle with fade in/out.
     *
     * @param array<int, array{path: string, portrait: bool}> $photos
     * @return array{0: string[], 1: float}
     */
    private function buildFfmpegCommand(string $intro, array $photos, string $end, string $jingle, string $out): array
    {
        $ffmpeg = config('services.youtube.ffmpeg', 'ffmpeg');
        $fps = self::FPS;
        $size = self::W . 'x' . self::H;

        // ── Inputs ──────────────────────────────────────────────────────────
        $args = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y'];
        $durations = [];

        $addImageInput = function (string $path, float $secs) use (&$args, &$durations, $fps) {
            array_push($args, '-loop', '1', '-framerate', (string) $fps, '-t', (string) $secs, '-i', $path);
            $durations[] = $secs;
        };

        $addImageInput($intro, self::INTRO_SECS);
        foreach ($photos as $p) {
            $addImageInput($p['path'], self::PHOTO_SECS);
        }
        $addImageInput($end, self::OUTRO_SECS);

        $audioIndex = count($durations);
        array_push($args, '-stream_loop', '-1', '-i', $jingle);

        // ── Per-segment filters ─────────────────────────────────────────────
        $filters = [];
        $norm = 'setsar=1,fps=' . $fps . ',format=yuv420p';

        // Cards are rendered at exactly 1280×720; the scale+pad is a no-op
        // safety net that also absorbs any future card-size change.
        $card = "scale=" . self::W . ':' . self::H . ':force_original_aspect_ratio=decrease,'
            . 'pad=' . self::W . ':' . self::H . ':(ow-iw)/2:(oh-ih)/2:color=' . self::CARD_BG . ',' . $norm;

        $filters[] = "[0:v]{$card}[v0]";

        foreach ($photos as $i => $p) {
            $in = $i + 1;
            if ($p['portrait']) {
                // Portrait: blurred cover backdrop + sharp fit-to-height
                // foreground (ReelMaker's letterbox treatment, landscape-ified).
                $filters[] = "[{$in}:v]split=2[bg{$in}][fg{$in}];"
                    . "[bg{$in}]scale=" . self::W . ':' . self::H . ":force_original_aspect_ratio=increase,"
                    . 'crop=' . self::W . ':' . self::H . ",boxblur=20:2[bgb{$in}];"
                    . "[fg{$in}]scale=-2:" . self::H . "[fgs{$in}];"
                    . "[bgb{$in}][fgs{$in}]overlay=(W-w)/2:(H-h)/2,{$norm}[v{$in}]";
            } else {
                // Landscape: cover-crop oversized, then Ken Burns via zoompan
                // (d=1 passthrough + per-output-frame zoom/pan expressions).
                // Alternate slow zoom-in and lateral pan for variety.
                $frames = (int) round(self::PHOTO_SECS * $fps);
                if ($i % 2 === 0) {
                    $zp = "zoompan=z='min(1.0+0.0014*on,1.14)'"
                        . ":x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'";
                } else {
                    $zp = "zoompan=z='1.08'"
                        . ":x='(iw-iw/zoom)*on/{$frames}':y='ih/2-(ih/zoom/2)'";
                }
                $filters[] = "[{$in}:v]scale=1600:900:force_original_aspect_ratio=increase,crop=1600:900,"
                    . $zp . ":d=1:s={$size}:fps={$fps},{$norm}[v{$in}]";
            }
        }

        $endIn = count($photos) + 1;
        $filters[] = "[{$endIn}:v]{$card}[v{$endIn}]";

        // ── Crossfade chain ─────────────────────────────────────────────────
        $n = count($durations);           // visual segments
        $fade = self::FADE_SECS;
        $prev = 'v0';
        $elapsed = 0.0;
        for ($k = 1; $k < $n; $k++) {
            $elapsed += $durations[$k - 1];
            $offset = $elapsed - $k * $fade;
            $label = $k === $n - 1 ? 'vout' : "x{$k}";
            $filters[] = "[{$prev}][v{$k}]xfade=transition=fade:duration={$fade}:offset="
                . number_format($offset, 3, '.', '') . "[{$label}]";
            $prev = $label;
        }
        $total = array_sum($durations) - ($n - 1) * $fade;

        // ── Audio: loop jingle to length, fade in/out ───────────────────────
        $fadeOutStart = number_format(max(0, $total - 1.5), 3, '.', '');
        $filters[] = "[{$audioIndex}:a]atrim=0:" . number_format($total, 3, '.', '')
            . ',afade=t=in:st=0:d=0.8,afade=t=out:st=' . $fadeOutStart . ':d=1.5[aout]';

        array_push(
            $args,
            '-filter_complex', implode(';', $filters),
            '-map', '[vout]', '-map', '[aout]',
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '21',
            '-r', (string) $fps, '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $out,
        );

        return [$args, $total];
    }

    private function run(array $cmd, string $cwd): void
    {
        // `nice -n 15` keeps the render from competing with API workers.
        if (PHP_OS_FAMILY !== 'Windows') {
            $cmd = array_merge(['nice', '-n', '15'], $cmd);
        }

        $process = new Process($cmd, $cwd, null, null, 600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'ffmpeg failed: ' . mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 2000)
            );
        }
    }
}
