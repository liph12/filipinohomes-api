<?php

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

test('the front page names Filipino Homes and declares the FH icons, so logo fetchers find them', function () {
    $page = $this->get('/')->assertOk()->assertSee('<title>Filipino Homes</title>', false);

    foreach (['/favicon.ico', '/favicon-32x32.png', '/android-chrome-192x192.png', '/apple-touch-icon.png', '/site.webmanifest'] as $file) {
        $page->assertSee($file, false);
        expect(filesize(public_path(ltrim($file, '/'))))->toBeGreaterThan(300);
    }
});
