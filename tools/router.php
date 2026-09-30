<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server, used for local development only.
 *
 *   php -S 127.0.0.1:12001 -t /workspace/project tools/router.php
 *
 * Static files are served as-is; .php requests are executed. The production
 * host is expected to run Apache/nginx with its own rewrite rules.
 */

$root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/');
$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

// Refuse to walk outside the document root.
if (str_contains($uri, '..')) {
    http_response_code(400);
    echo 'Bad request';
    return true;
}

$path = $root . $uri;

if (is_dir($path)) {
    $path = rtrim($path, '/') . '/index.html';
}

if (is_file($path) && str_ends_with($path, '.php')) {
    require $path;
    return true;
}

if (is_file($path)) {
    return false;   // let the built-in server stream it
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
$fallback = $root . '/humanforceresolve/404.html';
if (is_file($fallback)) {
    readfile($fallback);
} else {
    echo '404 Not Found';
}

return true;
