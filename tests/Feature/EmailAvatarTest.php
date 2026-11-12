<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /email/avatar — square crop for email person cards, so a non-square photo
 * isn't squashed in Gmail/Outlook. Allowlisted avatar hosts only.
 */
class EmailAvatarTest extends TestCase
{
    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 40, 40));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_a_wide_photo_comes_back_as_a_square_jpeg(): void
    {
        Http::fake(['*' => Http::response($this->png(300, 120), 200, ['Content-Type' => 'image/png'])]);

        $res = $this->get('/email/avatar?u='.urlencode('https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/avatars/wide.png'));

        $res->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        [$w, $h] = getimagesizefromstring($res->getContent());
        expect([$w, $h])->toBe([128, 128]);
    }

    public function test_refuses_hosts_that_are_not_avatar_hosts(): void
    {
        Http::fake();

        $this->get('/email/avatar?u='.urlencode('https://evil.example.com/a.png'))->assertNotFound();
        $this->get('/email/avatar?u='.urlencode('http://filipinohomes123.s3.ap-southeast-1.amazonaws.com/a.png'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_refuses_anything_that_is_not_an_image(): void
    {
        Http::fake(['*' => Http::response('<html>phish</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->get('/email/avatar?u='.urlencode('https://lh3.googleusercontent.com/a/not-an-image'))->assertNotFound();
    }
}
