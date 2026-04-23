<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$publicAssetsDir = $projectRoot . '/public/site-assets';
$viewsDir = $projectRoot . '/resources/views';

$pages = [
    'home' => 'https://thermenwartung-baxi.at/',
    'datenschutz' => 'https://thermenwartung-baxi.at/datenschutz/',
    'impressum' => 'https://thermenwartung-baxi.at/impressum/',
];

$downloaded = [];
$rewritten = [];

ensureDirectory($publicAssetsDir);
ensureDirectory($publicAssetsDir . '/css');
ensureDirectory($publicAssetsDir . '/js');
ensureDirectory($publicAssetsDir . '/images');
ensureDirectory($publicAssetsDir . '/fonts');
ensureDirectory($publicAssetsDir . '/misc');

foreach ($pages as $viewName => $url) {
    $html = fetchText($url);
    $html = normalizeEncoding($html);
    $rewritten[$viewName] = rewritePage($html, $url, $downloaded, $publicAssetsDir);
}

foreach ($rewritten as $viewName => $html) {
    file_put_contents($viewsDir . '/' . $viewName . '.blade.php', $html);
}

echo 'Migrated ' . count($pages) . ' pages and downloaded ' . count($downloaded) . " assets.\n";

function rewritePage(string $html, string $pageUrl, array &$downloaded, string $publicAssetsDir): string
{
    $siteRoot = 'https://thermenwartung-baxi.at';
    $routeExpression = routeExpressionForUrl($pageUrl);

    $html = preg_replace('/<link[^>]+https?:\/\/[^"\']*xmlrpc\.php[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<link[^>]+rel=("|\')profile("|\')[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<link[^>]+rel=("|\')alternate("|\')[^>]+oembed[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<link[^>]+type=("|\')application\/rss\+xml("|\')[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<link[^>]+rel=("|\')https:\/\/api\.w\.org\/("|\')[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<link[^>]+title=("|\')JSON("|\')[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<link[^>]+rel=("|\')shortlink("|\')[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<meta[^>]+name=("|\')generator("|\')[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<meta[^>]+name=("|\')msapplication-TileImage("|\')[^>]*>\s*/i', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]+type=("|\')application\/ld\+json("|\')[^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<style[^>]+id=("|\')wp-emoji-styles-inline-css("|\')[^>]*>.*?<\/style>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<!-- Page supported by LiteSpeed Cache.*?-->\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<div\s+style=\'position:absolute;left:-11344px;width:1087px;\'>.*?<\/div>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/\ssrcset="[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace('/<img[^>]+class="emoji"[^>]+alt="([^"]+)"[^>]*>/i', '$1', $html) ?? $html;

    $patterns = [
        '/(?P<attr>\s(?:href|src)=["\'])(?P<url>[^"\']+)(["\'])/i',
        '/(?P<attr>\s(?:data-src|data-lazy-src)=["\'])(?P<url>[^"\']+)(["\'])/i',
        '/(?P<attr>url\(["\']?)(?P<url>[^)"\']+)(["\']?\))/i',
    ];

    foreach ($patterns as $pattern) {
        $html = preg_replace_callback($pattern, function (array $matches) use (&$downloaded, $publicAssetsDir, $pageUrl, $siteRoot): string {
            $originalUrl = trim($matches['url']);

            if ($originalUrl === '' || str_starts_with($originalUrl, 'data:') || str_starts_with($originalUrl, 'mailto:') || str_starts_with($originalUrl, 'tel:') || str_starts_with($originalUrl, '#')) {
                return $matches[0];
            }

            $absoluteUrl = absolutizeUrl($originalUrl, $pageUrl);

            if (! str_starts_with($absoluteUrl, $siteRoot)) {
                return $matches[0];
            }

            if (preg_match('/\/(?:wp-admin|wp-json|xmlrpc\.php)(?:\/|$|\?)/i', $absoluteUrl)) {
                return $matches[0];
            }

            $localPath = downloadAsset($absoluteUrl, $downloaded, $publicAssetsDir);

            if ($localPath === null) {
                return $matches[0];
            }

            return $matches['attr'] . "{{ asset('" . $localPath . "') }}" . $matches[3];
        }, $html) ?? $html;
    }

    $html = preg_replace('/(<link[^>]+rel=["\']canonical["\'][^>]+href=["\'])[^"\']*(["\'])/i', '$1' . $routeExpression . '$2', $html) ?? $html;
    $html = preg_replace('/(<meta[^>]+property=["\']og:url["\'][^>]+content=["\'])[^"\']*(["\'])/i', '$1' . $routeExpression . '$2', $html) ?? $html;

    $html = str_replace([
        "{{ asset('site-assets/misc/index') }}",
        "{{ asset('site-assets/misc/datenschutz') }}",
        "{{ asset('site-assets/misc/impressum') }}",
        'https://thermenwartung-baxi.at/datenschutz/',
        'https://thermenwartung-baxi.at/impressum/',
        'https://thermenwartung-baxi.at/',
    ], [
        "{{ route('home') }}",
        "{{ route('datenschutz') }}",
        "{{ route('impressum') }}",
        "{{ route('datenschutz') }}",
        "{{ route('impressum') }}",
        "{{ route('home') }}",
    ], $html);

    $html = preg_replace('/\{\{\s*route\(\'home\'\)\s*\}\}wp-[^"\']+/i', "{{ route('home') }}", $html) ?? $html;
    $html = preg_replace('/href="\{\{\s*route\(\'(home|datenschutz|impressum)\'\)\s*\}\}\'site-assets\/misc\/[^"]+"/i', 'href="' . $routeExpression . '"', $html) ?? $html;

    $html = preg_replace('/<script[^>]*>\s*var wpcf7 = .*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]*id=("|\')wp-i18n-js-after("|\')[^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]*id=("|\')elementor-frontend-js-before("|\')[^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]*id=("|\')elementor-pro-frontend-js-before("|\')[^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]*type=["\']speculationrules["\'][^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]*id=["\']wp-emoji-settings["\'][^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]*>\s*\/\*! This file is auto-generated \*\/.*?<\/script>\s*/is', '', $html) ?? $html;
    $html = preg_replace('/<script[^>]*type=["\']module["\'][^>]*>.*?wpEmojiSettingsSupports.*?<\/script>\s*/is', '', $html) ?? $html;

    return repairMojibake($html);
}

function downloadAsset(string $url, array &$downloaded, string $publicAssetsDir): ?string
{
    if (isset($downloaded[$url])) {
        return $downloaded[$url];
    }

    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $folder = match (true) {
        in_array($extension, ['css'], true) => 'css',
        in_array($extension, ['js'], true) => 'js',
        in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'avif'], true) => 'images',
        in_array($extension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true) => 'fonts',
        default => 'misc',
    };

    $fileName = basename($path);
    if ($fileName === '' || $fileName === '/' || $fileName === '.') {
        $fileName = 'index';
    }

    $query = parse_url($url, PHP_URL_QUERY);
    $suffix = $query ? '-' . substr(sha1($query), 0, 10) : '';
    $safeName = sanitizeFileName(pathinfo($fileName, PATHINFO_FILENAME));
    $safeExtension = pathinfo($fileName, PATHINFO_EXTENSION);
    $finalName = $safeName . $suffix . ($safeExtension ? '.' . $safeExtension : '');

    $diskPath = $publicAssetsDir . '/' . $folder . '/' . $finalName;
    $publicPath = 'site-assets/' . $folder . '/' . $finalName;

    ensureDirectory(dirname($diskPath));

    $body = fetchBinary($url);
    if ($body === null) {
        return null;
    }

    file_put_contents($diskPath, $body);
    $downloaded[$url] = $publicPath;

    if ($extension === 'css') {
        $css = normalizeEncoding((string) $body);
        $css = rewriteCssUrls($css, $url, $downloaded, $publicAssetsDir);
        file_put_contents($diskPath, $css);
    }

    return $publicPath;
}

function rewriteCssUrls(string $css, string $cssUrl, array &$downloaded, string $publicAssetsDir): string
{
    return preg_replace_callback('/url\((["\']?)([^)"\']+)(["\']?)\)/i', function (array $matches) use ($cssUrl, &$downloaded, $publicAssetsDir): string {
        $originalUrl = trim($matches[2]);

        if ($originalUrl === '' || str_starts_with($originalUrl, 'data:') || str_starts_with($originalUrl, '#')) {
            return $matches[0];
        }

        $absoluteUrl = absolutizeUrl($originalUrl, $cssUrl);
        if (! str_starts_with($absoluteUrl, 'https://thermenwartung-baxi.at')) {
            return $matches[0];
        }

        $localPath = downloadAsset($absoluteUrl, $downloaded, $publicAssetsDir);

        if ($localPath === null) {
            return $matches[0];
        }

        return "url('/" . $localPath . "')";
    }, $css) ?? $css;
}

function fetchText(string $url): string
{
    $body = fetchBinary($url);

    if ($body === null) {
        throw new RuntimeException('Failed to fetch: ' . $url);
    }

    return (string) $body;
}

function fetchBinary(string $url): ?string
{
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

    $body = @file_get_contents($url, false, $context);

    return $body === false ? null : $body;
}

function absolutizeUrl(string $url, string $baseUrl): string
{
    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    $base = parse_url($baseUrl);
    $scheme = $base['scheme'] ?? 'https';
    $host = $base['host'] ?? '';

    if (str_starts_with($url, '/')) {
        return $scheme . '://' . $host . $url;
    }

    $basePath = $base['path'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

    return $scheme . '://' . $host . ($dir === '' ? '' : $dir) . '/' . ltrim($url, '/');
}

function normalizeEncoding(string $content): string
{
    return repairMojibake($content);
}

function repairMojibake(string $content): string
{
    $normalized = $content;

    for ($i = 0; $i < 3; $i++) {
        if (! preg_match('/Ã.|â.|Â|ðŸ/u', $normalized)) {
            break;
        }

        $converted = @mb_convert_encoding($normalized, 'UTF-8', 'Windows-1252');
        if (! is_string($converted) || $converted === $normalized) {
            $converted = @mb_convert_encoding($normalized, 'UTF-8', 'ISO-8859-1');
        }

        if (! is_string($converted) || $converted === $normalized) {
            break;
        }

        $normalized = $converted;
    }

    return $normalized;
}

function routeExpressionForUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    return match (rtrim($path, '/')) {
        '', '/' => "{{ route('home') }}",
        '/datenschutz' => "{{ route('datenschutz') }}",
        '/impressum' => "{{ route('impressum') }}",
        default => "{{ route('home') }}",
    };
}

function sanitizeFileName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'file';
    $name = trim($name, '-_.');

    return $name === '' ? 'file' : $name;
}

function ensureDirectory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}
