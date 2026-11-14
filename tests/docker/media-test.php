<?php
// Runs the app's own ffmpeg, ghostscript and GD code paths on generated sample files, inside the
// image. No database and no network: inputs are made locally, and outputs stay in /tmp.
// Used by tests/docker/smoke-test.sh; exits non-zero on any failure.

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dir = sys_get_temp_dir().'/fh-media-test';
@mkdir($dir);

function sh(array $args): void
{
    exec(implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $out, $rc);
    if ($rc !== 0) {
        throw new RuntimeException("failed: {$args[0]}\n".implode("\n", $out));
    }
}

// Sample inputs: 1280x720 cards, two landscape photos, one portrait photo, an MP3 jingle.
$ff = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i'];
sh([...$ff, 'color=c=0x152A4E:s=1280x720', '-frames:v', '1', "$dir/intro.png"]);
sh([...$ff, 'color=c=0x152A4E:s=1280x720', '-frames:v', '1', "$dir/end.png"]);
sh([...$ff, 'testsrc2=s=1920x1080', '-frames:v', '1', "$dir/land1.jpg"]);
sh([...$ff, 'testsrc2=s=1080x1920', '-frames:v', '1', "$dir/portrait.jpg"]);
sh([...$ff, 'testsrc2=s=1600x1200', '-frames:v', '1', "$dir/land2.jpg"]);
sh([...$ff, 'sine=frequency=440:duration=6', '-c:a', 'libmp3lame', "$dir/jingle.mp3"]);

// 1. ListingVideoComposer: its real command builder and runner (private, called by reflection).
$composer = $app->make(App\Services\YouTube\ListingVideoComposer::class);
[$cmd, $expected] = (new ReflectionMethod($composer, 'buildFfmpegCommand'))->invoke(
    $composer,
    "$dir/intro.png",
    [
        ['path' => "$dir/land1.jpg", 'portrait' => false],
        ['path' => "$dir/portrait.jpg", 'portrait' => true],
        ['path' => "$dir/land2.jpg", 'portrait' => false],
    ],
    "$dir/end.png",
    "$dir/jingle.mp3",
    "$dir/out.mp4",
);
$t = microtime(true);
(new ReflectionMethod($composer, 'run'))->invoke($composer, $cmd, $dir);
exec('ffprobe -v error -show_entries format=duration:stream=codec_name,width,height -of compact=p=0:nk=1 '.escapeshellarg("$dir/out.mp4"), $probe);
$duration = (float) end($probe);
if (abs($duration - $expected) > 0.5) {
    throw new RuntimeException("video is {$duration}s, expected {$expected}s");
}
printf("video: OK, %d KB, %.1fs rendered in %.1fs [%s]\n",
    filesize("$dir/out.mp4") / 1024, $duration, microtime(true) - $t, implode(' / ', $probe));

// 2. Intervention Image with the GD driver, using the encoders the app uses (WebP and JPEG).
$manager = new Intervention\Image\ImageManager(new Intervention\Image\Drivers\Gd\Driver());
foreach (['land1.jpg', 'portrait.jpg'] as $name) {
    $image = $manager->read("$dir/$name")->scaleDown(width: 800);
    $image->toWebp(quality: 80)->save("$dir/$name.webp");
    $image->toJpeg(quality: 80)->save("$dir/$name.small.jpg");
}
[$w, $h] = getimagesize("$dir/land1.jpg.webp");
printf("images: OK, GD %s, WebP %dx%d\n", gd_info()['GD Version'], $w, $h);

// 3. PdfOptimizer: a two-page PDF through the real service.
sh(['gs', '-q', '-sDEVICE=pdfwrite', '-o', "$dir/in.pdf", '-c',
    '/Helvetica findfont 24 scalefont setfont 72 720 moveto (Filipino Homes test page 1) show showpage '
    .'72 720 moveto (Page 2) show showpage']);
$result = $app->make(App\Services\PdfOptimizer::class)->optimize("$dir/in.pdf", false);
printf("pdf: status=%s original=%s optimized=%s\n",
    $result['status'], $result['original_size'] ?? '-', $result['optimized_size'] ?? '-');
if ($result['status'] !== 'compressed_file') {
    throw new RuntimeException('PdfOptimizer: '.($result['reason'] ?? 'no reason given'));
}
