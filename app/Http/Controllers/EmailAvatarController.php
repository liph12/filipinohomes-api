<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET /email/avatar?u=<avatar url> — a 128×128 square crop of a user's avatar
 * for the person cards in emails (resources/views/components/email/person).
 *
 * Email clients (Gmail, Outlook) ignore `object-fit`, so a non-square photo in
 * a fixed 64×64 <img> gets squashed. Cropping it here makes it square for
 * every client.
 *
 * NOT an open proxy: only avatar hosts we actually store (our S3 bucket,
 * Google and Facebook profile photos) are fetched, and the result is always
 * decoded and re-encoded as a JPEG — anything that isn't an image is a 404.
 */
class EmailAvatarController extends Controller
{
    private const SIZE = 128;

    private const ALLOWED_HOST = '/^(filipinohomes123\.s3\.ap-southeast-1\.amazonaws\.com|s3[.-]ap-southeast-1\.amazonaws\.com|lh\d+\.googleusercontent\.com|graph\.facebook\.com|[a-z0-9.-]+\.fbcdn\.net)$/i';

    public function __invoke(Request $request): Response
    {
        $url = (string) $request->query('u', '');
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ! preg_match(self::ALLOWED_HOST, $parts['host'] ?? '')) {
            abort(404);
        }

        $jpeg = Cache::remember('email-avatar:'.sha1($url), now()->addDays(7), function () use ($url) {
            try {
                $res = Http::timeout(8)->connectTimeout(4)->withOptions(['allow_redirects' => ['max' => 3]])->get($url);
                if (! $res->successful() || strlen($res->body()) > 8 * 1024 * 1024) {
                    return null;
                }

                return base64_encode((string) (new ImageManager(new Driver))
                    ->read($res->body())
                    ->cover(self::SIZE, self::SIZE)
                    ->toJpeg(85));
            } catch (Throwable) {
                return null;
            }
        });

        if (! $jpeg) {
            Cache::forget('email-avatar:'.sha1($url)); // try again next time
            abort(404);
        }

        return response(base64_decode($jpeg), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
