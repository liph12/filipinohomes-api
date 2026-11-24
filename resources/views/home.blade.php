<!DOCTYPE html>
{{--
    The API's own front page. Nobody browses here, but services that show a site's logo do (Claude's
    connector list reads this page for the icon of the connector at this address, as it does for
    fhiglobal.ae), so it names Filipino Homes and declares the FH mark the way a website does.
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Filipino Homes</title>
    <meta name="description" content="Filipino Homes API.">
    <meta name="robots" content="noindex">
    <link rel="icon" href="/favicon.ico">
    <link rel="icon" href="/favicon-16x16.png" sizes="16x16" type="image/png">
    <link rel="icon" href="/favicon-32x32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="/android-chrome-192x192.png" sizes="192x192" type="image/png">
    <link rel="icon" href="/android-chrome-512x512.png" sizes="512x512" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="/site.webmanifest">
    <meta property="og:site_name" content="Filipino Homes">
    <meta property="og:title" content="Filipino Homes">
    <meta property="og:image" content="{{ url('/android-chrome-512x512.png') }}">
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; background: #fff; color: #1f2a44; }
        main { text-align: center; }
        img { width: 96px; height: 96px; }
        a { color: #1f4fd1; }
    </style>
</head>
<body>
    <main>
        <img src="/android-chrome-192x192.png" alt="Filipino Homes">
        <h1>Filipino Homes</h1>
        <p>This is the Filipino Homes API. The website is at <a href="https://filipinohomes.com">filipinohomes.com</a>.</p>
    </main>
</body>
</html>
