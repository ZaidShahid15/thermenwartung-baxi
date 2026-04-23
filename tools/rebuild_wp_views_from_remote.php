<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$viewsDir = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
$publicDir = $root . DIRECTORY_SEPARATOR . 'public';
$layoutDir = $viewsDir . DIRECTORY_SEPARATOR . 'layouts';

$pages = [
    'home' => 'https://thermenservice-vaillant.at/',
    'datenschutz' => 'https://thermenservice-vaillant.at/datenschutz/',
    'impressum' => 'https://thermenservice-vaillant.at/impressum/',
];

$pageHtml = [];
$downloadMap = [];
$downloadedMap = [];

foreach ($pages as $page => $url) {
    $html = downloadText($url);
    if ($html === '') {
        throw new RuntimeException("Failed to download page HTML: {$url}");
    }

    $pageHtml[$page] = $html;

    preg_match_all('/(?:href|src)=["\']([^"\']+)["\']/i', $html, $matches);
    foreach ($matches[1] as $assetUrl) {
        if (! shouldDownload($assetUrl)) {
            continue;
        }

        $downloadMap[$assetUrl] = buildLocalAssetPath($assetUrl);
    }
}

foreach ($downloadMap as $url => $relativePath) {
    $destination = $publicDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (downloadBinary($url, $destination)) {
        $downloadedMap[$url] = $relativePath;
    }
}

if (! is_dir($layoutDir) && ! mkdir($layoutDir, 0777, true) && ! is_dir($layoutDir)) {
    throw new RuntimeException("Unable to create layout directory: {$layoutDir}");
}

$layout = <<<'BLADE'
<!DOCTYPE html>
<html lang="en-US" prefix="og: https://ogp.me/ns#">
<head>
    @yield('head')
</head>
<body class="@yield('body_class')">
    @yield('content')
</body>
</html>
BLADE;

file_put_contents($layoutDir . DIRECTORY_SEPARATOR . 'site.blade.php', $layout);

foreach ($pageHtml as $page => $html) {
    $localized = localizeHtml($html, $downloadedMap);
    [$head, $bodyClass, $body] = splitDocument($localized, $page);

    $head = normalizeImportedText($head);
    $body = normalizeImportedText($body);
    $head = escapeBladeLiteralAtSigns($head);
    $body = escapeBladeLiteralAtSigns($body);

    if ($page === 'home') {
        $brokenImage = 'https://thermenservice-vaillant.at/wp-content/uploads/2025/09/csm_Google-Bewertungen-kaufen_b02868211a.png';
        $fallbackImage = "{{ asset('assets/external/thermenservice-vaillant.at/wp-content/uploads/2025/08/vaillant-logo-272x72-1888261-1.png') }}";
        $body = str_replace($brokenImage, $fallbackImage, $body);
    }

    file_put_contents($viewsDir . DIRECTORY_SEPARATOR . $page . '.blade.php', buildBladeView($head, $bodyClass, $body));
}

echo "Rebuilt views. Localized assets: " . count($downloadedMap) . PHP_EOL;

function makeContext()
{
    return stream_context_create([
        'http' => [
            'follow_location' => 1,
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 60,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
}

function downloadText(string $url): string
{
    $content = @file_get_contents(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), false, makeContext());
    return $content === false ? '' : $content;
}

function downloadBinary(string $url, string $destination): bool
{
    if (is_file($destination) && filesize($destination) > 0) {
        return true;
    }

    $directory = dirname($destination);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create asset directory: {$directory}");
    }

    $data = @file_get_contents(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), false, makeContext());
    if ($data === false) {
        fwrite(STDERR, "Skipped asset: {$url}" . PHP_EOL);
        return false;
    }

    file_put_contents($destination, $data);
    return true;
}

function shouldDownload(string $url): bool
{
    if (! preg_match('~^https?://~i', $url)) {
        return false;
    }

    $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
    if ($parts === false || empty($parts['host'])) {
        return false;
    }

    $host = strtolower($parts['host']);
    $path = $parts['path'] ?? '';

    if (str_contains($host, 'thermenservice-vaillant.at')) {
        return preg_match('~\.(?:css|js|png|jpe?g|webp|gif|svg|ico)$~i', $path) === 1
            || str_contains($path, '/wp-content/')
            || str_contains($path, '/wp-includes/');
    }

    return $host === 'fonts.googleapis.com' || $host === 'fonts.bunny.net';
}

function buildLocalAssetPath(string $url): string
{
    $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
    $parts = parse_url($decoded);

    if ($parts === false || empty($parts['host'])) {
        throw new RuntimeException("Invalid asset URL: {$url}");
    }

    $host = strtolower($parts['host']);
    $path = ltrim($parts['path'] ?? '/asset', '/');

    if ($path === '') {
        $path = 'asset';
    }

    if (pathinfo($path, PATHINFO_EXTENSION) === '') {
        $path .= '.txt';
    }

    return 'assets/external/' . $host . '/' . $path;
}

function localizeHtml(string $html, array $downloadMap): string
{
    foreach ($downloadMap as $url => $localPath) {
        $replacement = "{{ asset('{$localPath}') }}";
        $html = str_replace($url, $replacement, $html);

        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        if ($decoded !== $url) {
            $html = str_replace($decoded, $replacement, $html);
        }
    }

    $routeReplacements = [
        "href='https://thermenservice-vaillant.at/'" => 'href="{{ route(\'home\') }}"',
        'href="https://thermenservice-vaillant.at/"' => 'href="{{ route(\'home\') }}"',
        "href='https://thermenservice-vaillant.at/datenschutz/'" => 'href="{{ route(\'datenschutz\') }}"',
        'href="https://thermenservice-vaillant.at/datenschutz/"' => 'href="{{ route(\'datenschutz\') }}"',
        "href='https://thermenservice-vaillant.at/impressum/'" => 'href="{{ route(\'impressum\') }}"',
        'href="https://thermenservice-vaillant.at/impressum/"' => 'href="{{ route(\'impressum\') }}"',
        "href='https://thermenservice-vaillant.at/?p=136'" => 'href="{{ route(\'datenschutz\') }}"',
        'href="https://thermenservice-vaillant.at/?p=136"' => 'href="{{ route(\'datenschutz\') }}"',
        "href='https://thermenservice-vaillant.at/?p=146'" => 'href="{{ route(\'impressum\') }}"',
        'href="https://thermenservice-vaillant.at/?p=146"' => 'href="{{ route(\'impressum\') }}"',
    ];

    return str_replace(array_keys($routeReplacements), array_values($routeReplacements), $html);
}

function splitDocument(string $html, string $page): array
{
    if (! preg_match('~<head>(.*)</head>~is', $html, $headMatch)) {
        throw new RuntimeException("Unable to locate <head> for {$page}");
    }

    if (! preg_match('~<body\b([^>]*)>(.*)</body>~is', $html, $bodyMatch)) {
        throw new RuntimeException("Unable to locate <body> for {$page}");
    }

    $head = trim($headMatch[1]);
    $bodyAttrs = $bodyMatch[1];
    $body = trim($bodyMatch[2]);

    $bodyClass = '';
    if (preg_match('/class="([^"]*)"/i', $bodyAttrs, $classMatch)) {
        $bodyClass = $classMatch[1];
    }

    return [$head, $bodyClass, $body];
}

function buildBladeView(string $head, string $bodyClass, string $body): string
{
    $escapedBodyClass = str_replace("'", "\\'", $bodyClass);

    return <<<BLADE
@extends('layouts.site')

@section('head')
{$head}
@endsection

@section('body_class', '{$escapedBodyClass}')

@section('content')
{$body}
@endsection

BLADE;
}

function normalizeImportedText(string $value): string
{
    $replacements = [
        'Ã¤' => '&auml;',
        'Ã„' => '&Auml;',
        'Ã¶' => '&ouml;',
        'Ã–' => '&Ouml;',
        'Ã¼' => '&uuml;',
        'Ãœ' => '&Uuml;',
        'ÃŸ' => '&szlig;',
        'â€“' => '&ndash;',
        'â€”' => '&mdash;',
        'â‚¬' => '&euro;',
        'Â ' => '&nbsp;',
        'â€ž' => '&bdquo;',
        'â€œ' => '&ldquo;',
        'â€' => '&rdquo;',
        'â€™' => '&rsquo;',
        'â€²' => '&prime;',
        'â€¦' => '&hellip;',
        'â€¹' => '',
    ];

    $value = str_replace(array_keys($replacements), array_values($replacements), $value);

    $copyFixes = [
        'Zertifiizierter' => 'Zertifizierter',
        'Addresse:' => 'Adresse:',
    ];

    return str_replace(array_keys($copyFixes), array_values($copyFixes), $value);
}

function escapeBladeLiteralAtSigns(string $value): string
{
    $replacements = [
        '@context' => '@@context',
        '@graph' => '@@graph',
        '@type' => '@@type',
        '@id' => '@@id',
        '@media' => '@@media',
        '@font-face' => '@@font-face',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $value);
}
