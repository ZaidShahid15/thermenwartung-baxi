<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$views = [
    $root . '/resources/views/home.blade.php',
    $root . '/resources/views/datenschutz.blade.php',
    $root . '/resources/views/impressum.blade.php',
];

foreach ($views as $view) {
    $contents = file_get_contents($view);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$view}");
    }

    $contents = preg_replace('/=\'(\{\{\s*(?:asset|route)\([^\)]*\)\s*\}\})\'/', '="$1"', $contents);

    file_put_contents($view, $contents);
}

echo "Blade quote normalization complete." . PHP_EOL;
