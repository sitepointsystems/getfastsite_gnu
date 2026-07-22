<?php

declare(strict_types=1);

/**
 * Dev-only router for `php -S`. Serves an existing static file as-is;
 * everything else goes through the front controller — so API paths that
 * look like files (e.g. .../shell/file/templates/base.html.twig) still
 * reach the app. Production uses nginx try_files instead.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) {
    return false; // let the built-in server serve the static asset
}

require __DIR__ . '/index.php';
