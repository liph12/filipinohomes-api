<?php

namespace App\Services\Birthday;

use App\Support\AvatarUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Composites the agent's photo + name onto the gold "Happy Birthday" poster
 * (resources/birthday/poster.png, 1024×1536) with GD.
 *
 * The poster is a flat design image, so the two editable areas are fixed
 * pixel regions measured from the artwork:
 *   - Photo: the gold ring. Inner circle centre (509, 793), radius 260. The
 *     ribbon plaque overlaps the ring's bottom from y=999, so the photo is
 *     clipped there too — otherwise it would paint over the plaque's top edge.
 *   - Name: the ribbon plaque, inner box x 250–770, y 1002–1105 (the heart
 *     sits on the bottom edge, so text stays above ~1105).
 *
 * If the poster artwork is ever replaced, re-measure these constants.
 *
 * Output is JPEG (the poster has no transparency; PNG would be ~2 MB per
 * email image). Fonts are bundled (OFL): Cinzel Bold for the name — the
 * closest match to the poster's "BIRTHDAY!" lettering.
 */
class BirthdayPosterService
{
    private const POSTER = 'birthday/poster.png';

    private const FONT = 'birthday/Cinzel-Bold.ttf';

    private const CIRCLE_CX = 509;

    private const CIRCLE_CY = 793;

    private const CIRCLE_R = 258;      // 2px inside the ring's inner edge

    private const CIRCLE_CLIP_Y = 999; // plaque top edge — nothing below

    private const PLAQUE_X0 = 250;

    private const PLAQUE_X1 = 770;

    private const PLAQUE_Y0 = 1002;

    private const PLAQUE_Y1 = 1105;

    private const GOLD = [0xF3, 0xCB, 0x66];

    private const GOLD_DARK = [0x8A, 0x63, 0x14];

    private const SHADOW = [0x0A, 0x08, 0x03];

    /**
     * Render the poster for one celebrant.
     *
     * @param  string  $fullName  Display name (first + last, no middle name).
     * @param  string|null  $photoUrl  Absolute URL of the avatar; null → initials.
     * @return string JPEG binary.
     */
    public function render(string $fullName, ?string $photoUrl): string
    {
        $poster = imagecreatefrompng(resource_path(self::POSTER));
        if ($poster === false) {
            throw new \RuntimeException('Birthday poster artwork could not be loaded.');
        }
        imagealphablending($poster, true);

        $photo = $photoUrl ? $this->fetchPhoto($photoUrl) : null;
        $circle = $photo
            ? $this->circularPhoto($photo)
            : $this->initialsDisc($fullName);

        $d = self::CIRCLE_R * 2;
        imagecopy($poster, $circle, self::CIRCLE_CX - self::CIRCLE_R, self::CIRCLE_CY - self::CIRCLE_R, 0, 0, $d, $d);

        $this->drawName($poster, $fullName);

        ob_start();
        imagejpeg($poster, null, 88);

        return (string) ob_get_clean();
    }

    /**
     * Render + upload to S3. Returns ['url' => public URL, 'jpeg' => bytes,
     * 'filename' => download name] or null on any failure, so the email can
     * still go out without the poster.
     *
     * The object is stored with Content-Disposition: attachment, so the
     * "Download poster" link in the email saves the file instead of opening
     * it in a browser tab.
     *
     * @return array{url:string, jpeg:string, filename:string}|null
     */
    public function renderToS3(string $fullName, ?string $photoUrl, string $keyPrefix, ?string $basename = null): ?array
    {
        try {
            $jpeg = $this->render($fullName, $photoUrl);
            $filename = self::downloadFilename($fullName);
            $key = trim($keyPrefix, '/').'/'.($basename ?: Str::slug($fullName).'-'.Str::lower(Str::random(8)).'.jpg');
            $ok = Storage::disk('s3')->put($key, $jpeg, [
                'visibility' => 'public',
                'ContentType' => 'image/jpeg',
                'ContentDisposition' => 'attachment; filename="'.$filename.'"',
            ]);
            if (! $ok) {
                Log::warning('Birthday poster S3 upload failed', ['key' => $key]);

                return null;
            }

            return [
                'url' => rtrim((string) config('filesystems.disks.s3.url'), '/').'/'.$key,
                'jpeg' => $jpeg,
                'filename' => $filename,
            ];
        } catch (\Throwable $e) {
            Log::warning('Birthday poster render failed', ['name' => $fullName, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The poster for one agent on one day, at a deterministic S3 key so the
     * 07:00 greeting job and the admin digest share a single render/upload:
     * whichever runs first uploads, the other reuses the object.
     *
     * @return array{url:string, jpeg:string, filename:string}|null
     */
    public function forAgent(int $agentId, string $fullName, ?string $photoUrl, string $date): ?array
    {
        $key = 'birthday-greetings/'.str_replace('-', '/', $date)."/agent-{$agentId}.jpg";
        $disk = Storage::disk('s3');

        try {
            if ($disk->exists($key) && ($jpeg = $disk->get($key)) !== null) {
                return [
                    'url' => rtrim((string) config('filesystems.disks.s3.url'), '/').'/'.$key,
                    'jpeg' => $jpeg,
                    'filename' => self::downloadFilename($fullName),
                ];
            }
        } catch (\Throwable $e) {
            Log::info('Birthday poster: S3 lookup failed, re-rendering', ['key' => $key, 'error' => $e->getMessage()]);
        }

        return $this->renderToS3($fullName, $photoUrl, dirname($key), basename($key));
    }

    public static function downloadFilename(string $fullName): string
    {
        return 'happy-birthday-'.Str::slug($fullName).'.jpg';
    }

    /**
     * Pick the avatar URL for an agent row: agents.avatar (JSON array, first
     * entry) then users.avatar, both run through the legacy-URL repair.
     */
    public static function avatarFor(mixed $agentAvatar, ?string $userAvatar): ?string
    {
        if (is_string($agentAvatar) && Str::startsWith($agentAvatar, '[')) {
            $agentAvatar = json_decode($agentAvatar, true);
        }
        $candidate = is_array($agentAvatar) ? ($agentAvatar[0] ?? null) : $agentAvatar;
        $url = AvatarUrl::clean($candidate ?: $userAvatar);

        return is_string($url) && Str::startsWith($url, ['http://', 'https://']) ? $url : null;
    }

    /** Download + decode; null on any failure (then initials are drawn). */
    private function fetchPhoto(string $url): ?\GdImage
    {
        try {
            $res = Http::timeout(12)->withHeaders(['Accept' => 'image/*'])->get($url);
            if (! $res->successful()) {
                return null;
            }
            $img = @imagecreatefromstring($res->body());

            return $img === false ? null : $img;
        } catch (\Throwable $e) {
            Log::info('Birthday poster: avatar fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Cover-crop the photo into a D×D canvas, then cut it into an anti-aliased
     * circle (per-pixel alpha from distance to centre) clipped at the plaque.
     */
    private function circularPhoto(\GdImage $src): \GdImage
    {
        $r = self::CIRCLE_R;
        $d = $r * 2;
        $sw = imagesx($src);
        $sh = imagesy($src);

        // Cover crop: centre square (faces are usually centred; a slight upward
        // bias keeps heads in frame on portrait shots).
        $side = min($sw, $sh);
        $sx = (int) (($sw - $side) / 2);
        $sy = $sh > $sw ? (int) (($sh - $side) * 0.35) : 0;

        $canvas = imagecreatetruecolor($d, $d);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $src, 0, 0, $sx, $sy, $d, $d, $side, $side);

        $this->applyCircleMask($canvas);

        return $canvas;
    }

    /** Dark disc with gold initials for agents without a usable photo. */
    private function initialsDisc(string $fullName): \GdImage
    {
        $r = self::CIRCLE_R;
        $d = $r * 2;
        $canvas = imagecreatetruecolor($d, $d);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0x14, 0x17, 0x1F, 0));

        $initials = collect(preg_split('/\s+/', trim($fullName)) ?: [])
            ->filter()
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
            ->take(2)
            ->implode('');
        $initials = $initials !== '' ? $initials : '?';

        imagealphablending($canvas, true);
        $font = resource_path(self::FONT);
        $size = 170;
        $box = imagettfbbox($size, 0, $font, $initials);
        $tw = $box[2] - $box[0];
        $th = $box[1] - $box[7];
        $x = (int) (($d - $tw) / 2 - $box[0]);
        $y = (int) (($d + $th) / 2 - $box[1]);
        imagettftext($canvas, $size, 0, $x + 3, $y + 4, imagecolorallocate($canvas, ...self::SHADOW), $font, $initials);
        imagettftext($canvas, $size, 0, $x, $y, imagecolorallocate($canvas, ...self::GOLD), $font, $initials);
        imagealphablending($canvas, false);

        $this->applyCircleMask($canvas);

        return $canvas;
    }

    private function applyCircleMask(\GdImage $canvas): void
    {
        $r = self::CIRCLE_R;
        $d = $r * 2;
        $cx = $r - 0.5;
        $cy = $r - 0.5;
        $clipLocal = self::CIRCLE_CLIP_Y - (self::CIRCLE_CY - $r); // rows >= this are hidden

        for ($y = 0; $y < $d; $y++) {
            for ($x = 0; $x < $d; $x++) {
                $dist = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2);
                $coverage = max(0.0, min(1.0, $r - $dist + 0.5));
                if ($y >= $clipLocal) {
                    $coverage = 0.0;
                } elseif ($y === $clipLocal - 1) {
                    $coverage *= 0.5;
                }
                if ($coverage >= 1.0) {
                    continue;
                }
                $rgb = imagecolorat($canvas, $x, $y);
                $alpha = 127 - (int) round($coverage * 127);
                imagesetpixel($canvas, $x, $y, imagecolorallocatealpha(
                    $canvas, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF, $alpha
                ));
            }
        }
    }

    /**
     * Gold name in the ribbon plaque. One line whenever it fits (shrinking
     * down to 30px first — Cinzel caps stay legible at that size across the
     * 540px plaque); only then wrap at the last space onto two lines, sized
     * so both fit in the plaque's ~100px height without touching the heart.
     */
    private function drawName(\GdImage $poster, string $fullName): void
    {
        $font = resource_path(self::FONT);
        $maxW = self::PLAQUE_X1 - self::PLAQUE_X0;
        $cx = (self::PLAQUE_X0 + self::PLAQUE_X1) / 2;
        $cy = (self::PLAQUE_Y0 + self::PLAQUE_Y1) / 2;
        $text = self::titleCase(trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName));

        $lines = [$text];
        $size = 58;
        for (; $size >= 30; $size -= 2) {
            if ($this->textWidth($font, $size, $text) <= $maxW) {
                break;
            }
        }
        if ($this->textWidth($font, $size, $text) > $maxW && mb_strpos($text, ' ') !== false) {
            $lines = $this->balancedSplit($font, $text);
            for ($size = 36; $size >= 22; $size -= 2) {
                $widest = max(array_map(fn ($l) => $this->textWidth($font, $size, $l), $lines));
                if ($widest <= $maxW) {
                    break;
                }
            }
            $cy -= 4; // keep clear of the heart on the bottom edge
        }

        $gold = imagecolorallocate($poster, ...self::GOLD);
        $shadow = imagecolorallocatealpha($poster, ...[...self::SHADOW, 30]);
        $edge = imagecolorallocatealpha($poster, ...[...self::GOLD_DARK, 60]);

        // Cap-height based metrics so line slots are tight but not cramped.
        $capBox = imagettfbbox($size, 0, $font, 'H');
        $capH = $capBox[1] - $capBox[7];
        $lineH = count($lines) > 1 ? $capH * 1.45 : $capH;
        $totalH = $lineH * count($lines);
        $top = $cy - $totalH / 2;

        foreach ($lines as $i => $line) {
            $box = imagettfbbox($size, 0, $font, $line);
            $tw = $box[2] - $box[0];
            $x = (int) round($cx - $tw / 2 - $box[0]);
            // Baseline: slot top + centred cap height (ignores descender noise).
            $y = (int) round($top + $lineH * $i + ($lineH + $capH) / 2);
            imagettftext($poster, $size, 0, $x + 2, $y + 3, $shadow, $font, $line);
            imagettftext($poster, $size, 0, $x + 1, $y + 1, $edge, $font, $line);
            imagettftext($poster, $size, 0, $x, $y, $gold, $font, $line);
        }
    }

    /**
     * Split into two lines at the space that leaves the narrower widest line
     * (never splits inside a hyphenated surname).
     *
     * @return array{0:string,1:string}
     */
    private function balancedSplit(string $font, string $text): array
    {
        $words = explode(' ', $text);
        $best = null;
        $bestW = PHP_INT_MAX;
        for ($i = 1; $i < count($words); $i++) {
            $a = implode(' ', array_slice($words, 0, $i));
            $b = implode(' ', array_slice($words, $i));
            $w = max($this->textWidth($font, 40, $a), $this->textWidth($font, 40, $b));
            if ($w < $bestW) {
                $bestW = $w;
                $best = [$a, $b];
            }
        }

        return $best ?? [$text, ''];
    }

    /**
     * "MICHAEL ANGELO joaquin" → "Michael Angelo Joaquin". Capitalises after
     * spaces, hyphens and apostrophes (Villanueva-Santos, O'Neil).
     */
    public static function titleCase(string $name): string
    {
        $lower = mb_strtolower(trim($name));

        return preg_replace_callback('/(^|[\s\-\'])(\p{L})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $lower) ?? $name;
    }

    private function textWidth(string $font, int $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box[2] - $box[0];
    }
}
