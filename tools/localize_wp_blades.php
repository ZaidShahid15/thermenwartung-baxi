<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$viewsDir = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
$publicDir = $root . DIRECTORY_SEPARATOR . 'public';
$layoutDir = $viewsDir . DIRECTORY_SEPARATOR . 'layouts';

$pages = [
    'home' => $viewsDir . DIRECTORY_SEPARATOR . 'home.blade.php',
    'datenschutz' => $viewsDir . DIRECTORY_SEPARATOR . 'datenschutz.blade.php',
    'impressum' => $viewsDir . DIRECTORY_SEPARATOR . 'impressum.blade.php',
];

$downloadMap = [];
$downloadedMap = [];

foreach ($pages as $page => $path) {
    if (! is_file($path)) {
        throw new RuntimeException("View not found: {$path}");
    }

    $html = file_get_contents($path);
    if ($html === false) {
        throw new RuntimeException("Unable to read view: {$path}");
    }

    preg_match_all('/(?:href|src)=["\']([^"\']+)["\']/i', $html, $matches);

    foreach ($matches[1] as $url) {
        if (! shouldDownload($url)) {
            continue;
        }

        $downloadMap[$url] = buildLocalAssetPath($url);
    }
}

foreach ($downloadMap as $url => $relativePath) {
    $destination = $publicDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (downloadAsset($url, $destination)) {
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

foreach ($pages as $page => $path) {
    $html = file_get_contents($path);
    if ($html === false) {
        throw new RuntimeException("Unable to re-read view: {$path}");
    }

    $localized = localizeHtml($html, $downloadedMap);
    [$head, $bodyClass, $body] = splitDocument($localized, $page);

    $blade = buildBladeView($head, $bodyClass, $body);
    file_put_contents($path, $blade);
}

echo "Localized assets: " . count($downloadedMap) . PHP_EOL;

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

    if ($host === 'fonts.googleapis.com' || $host === 'fonts.bunny.net') {
        return true;
    }

    return false;
}

function buildLocalAssetPath(string $url): string
{
    $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
    $parts = parse_url($decoded);

    if ($parts === false || empty($parts['host'])) {
        throw new RuntimeException("Invalid asset URL: {$url}");
    }

    $host = strtolower($parts['host']);
    $path = $parts['path'] ?? '/asset';
    $path = ltrim($path, '/');

    if ($path === '') {
        $path = 'asset';
    }

    $extension = pathinfo($path, PATHINFO_EXTENSION);
    if ($extension === '') {
        $extension = 'txt';
        $path .= '.txt';
    }

    return 'assets/external/' . $host . '/' . $path;
}

function downloadAsset(string $url, string $destination): bool
{
    if (is_file($destination) && filesize($destination) > 0) {
        return true;
    }

    $directory = dirname($destination);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create asset directory: {$directory}");
    }

    $context = stream_context_create([
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

    $data = @file_get_contents(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), false, $context);
    if ($data === false) {
        fwrite(STDERR, "Skipped asset: {$url}" . PHP_EOL);
        return false;
    }

    file_put_contents($destination, $data);
    return true;
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
