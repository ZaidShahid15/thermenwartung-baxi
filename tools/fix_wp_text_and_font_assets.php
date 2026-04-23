<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$views = [
    $root . '/resources/views/home.blade.php',
    $root . '/resources/views/datenschutz.blade.php',
    $root . '/resources/views/impressum.blade.php',
];

$assetsRoot = $root . '/public/assets/external';

foreach ($views as $view) {
    $contents = file_get_contents($view);
    if ($contents === false) {
        throw new RuntimeException("Unable to read view: {$view}");
    }

    if (preg_match('/Ã|â€“|â€”|Â|�|Æ’|€™|œ/', $contents) === 1) {
        $contents = repairMojibake($contents);
        file_put_contents($view, $contents);
    }
}

$cssFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($assetsRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($cssFiles as $file) {
    if (! $file->isFile() || strtolower($file->getExtension()) !== 'css') {
        continue;
    }

    $cssPath = $file->getPathname();
    $css = file_get_contents($cssPath);
    if ($css === false) {
        continue;
    }

    if (! preg_match_all('/url\(([^)]+)\)/i', $css, $matches)) {
        continue;
    }

    foreach ($matches[1] as $rawUrl) {
        $asset = trim($rawUrl, " \t\n\r\0\x0B'\"");

        if ($asset === '' || str_starts_with($asset, 'data:')) {
            continue;
        }

        if (preg_match('~^https?://~i', $asset)) {
            downloadAbsoluteAsset($assetsRoot, $asset);
            continue;
        }

        if ($asset[0] === '/' || str_starts_with($asset, '#')) {
            continue;
        }

        downloadRelativeCssAsset($assetsRoot, $cssPath, $asset);
    }
}

echo "Text and font asset repair complete." . PHP_EOL;

function repairMojibake(string $value): string
{
    $best = $value;
    $bestScore = mojibakeScore($value);
    $current = $value;

    for ($i = 0; $i < 4; $i++) {
        $converted = iconv('Windows-1252', 'UTF-8//IGNORE', $current);
        if ($converted === false || $converted === $current) {
            break;
        }

        $score = mojibakeScore($converted);
        if ($score <= $bestScore) {
            $best = $converted;
            $bestScore = $score;
        }

        $current = $converted;
    }

    return $best;
}

function mojibakeScore(string $value): int
{
    preg_match_all('/Ã|â€“|â€”|Â|�|Æ’|€™|œ|¢|¤|¶|¼|Ÿ/', $value, $matches);
    return count($matches[0]);
}

function downloadAbsoluteAsset(string $assetsRoot, string $url): void
{
    $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
    $parts = parse_url($decoded);

    if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
        return;
    }

    $relative = $parts['host'] . '/' . ltrim($parts['path'], '/');
    $destination = $assetsRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    downloadTo($decoded, $destination);
}

function downloadRelativeCssAsset(string $assetsRoot, string $cssPath, string $relativeUrl): void
{
    $normalizedAssetsRoot = str_replace('\\', '/', realpath($assetsRoot) ?: $assetsRoot);
    $normalizedCssPath = str_replace('\\', '/', realpath($cssPath) ?: $cssPath);

    if (! str_starts_with($normalizedCssPath, $normalizedAssetsRoot . '/')) {
        return;
    }

    $localRelativeCssPath = substr($normalizedCssPath, strlen($normalizedAssetsRoot) + 1);
    $segments = explode('/', $localRelativeCssPath);
    $host = array_shift($segments);

    if ($host === null || $host === '') {
        return;
    }

    $cssRemoteDir = implode('/', array_slice($segments, 0, -1));
    $relativePathWithoutQuery = preg_replace('/[?#].*/', '', $relativeUrl) ?? $relativeUrl;
    $remotePath = normalizePath('/' . $cssRemoteDir . '/' . $relativePathWithoutQuery);
    $remoteUrl = 'https://' . $host . $remotePath;

    $destination = dirname($cssPath) . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePathWithoutQuery);
    downloadTo($remoteUrl, $destination);
}

function normalizePath(string $path): string
{
    $segments = [];

    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($segments);
            continue;
        }

        $segments[] = $segment;
    }

    return '/' . implode('/', $segments);
}

function downloadTo(string $url, string $destination): void
{
    if (is_file($destination) && filesize($destination) > 0) {
        return;
    }

    $directory = dirname($destination);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create directory: {$directory}");
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

    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        fwrite(STDERR, "Skipped asset: {$url}" . PHP_EOL);
        return;
    }

    file_put_contents($destination, $data);
}
