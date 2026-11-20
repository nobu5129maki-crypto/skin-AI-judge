<?php
require __DIR__ . '/auth.php';

function skin_deny()
{
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "ログインが必要です\n";
    exit;
}

function skin_miss()
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "見つかりません\n";
    exit;
}

function skin_content_type($path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = array(
        'html' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'webmanifest' => 'application/manifest+json; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'xml' => 'application/xml; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    );
    if (isset($map[$ext])) {
        return $map[$ext];
    }
    return 'application/octet-stream';
}

function skin_is_install_file($relative)
{
    $relative = str_replace('\\', '/', (string) $relative);
    $relative = rawurldecode($relative);
    $relative = ltrim($relative, '/');
    $allowed = array(
        'manifest.json' => true,
        'sw.js' => true,
        'icon-192.png' => true,
        'icon-512.png' => true,
    );
    return isset($allowed[$relative]);
}

function skin_requested_path()
{
    if (isset($_GET['mw_path'])) {
        return (string) $_GET['mw_path'];
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = rawurldecode(is_string($path) ? $path : '');
    $base = skin_cookie_path();
    if ($base !== '/' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    return ltrim($path, '/');
}

function skin_resolve($relative)
{
    $relative = str_replace('\\', '/', (string) $relative);
    $relative = rawurldecode($relative);
    if ($relative === '' || strpos($relative, "\0") !== false) {
        return '';
    }
    $parts = explode('/', $relative);
    $safe = array();
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..' || $part[0] === '.') {
            return '';
        }
        $safe[] = $part;
    }
    $relative = implode('/', $safe);
    if ($relative === '') {
        return '';
    }
    if (preg_match('/\.php$/i', $relative)) {
        return '';
    }

    $root = realpath(__DIR__);
    if ($root === false) {
        return '';
    }
    $candidate = $root . '/' . $relative;
    if (is_dir($candidate)) {
        $candidate = rtrim($candidate, '/') . '/index.html';
    } elseif (!is_file($candidate)) {
        $asDir = $root . '/' . $relative . '/index.html';
        $asHtml = $root . '/' . $relative . '.html';
        if (is_file($asDir)) {
            $candidate = $asDir;
        } elseif (is_file($asHtml)) {
            $candidate = $asHtml;
        }
    }
    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return '';
    }
    $prefix = rtrim($root, '/') . '/';
    if (strpos($real, $prefix) !== 0) {
        return '';
    }
    if (substr($real, -4) === '.php') {
        return '';
    }
    return $real;
}

$requested = skin_requested_path();
$publicInstall = skin_is_install_file($requested);

if (!$publicInstall && !skin_logged_in()) {
    skin_deny();
}

$file = skin_resolve($requested);
if ($file === '') {
    skin_miss();
}
if ($publicInstall) {
    $root = realpath(__DIR__);
    $prefix = $root === false ? '' : rtrim($root, '/') . '/';
    $suffix = $prefix !== '' && strpos($file, $prefix) === 0 ? substr($file, strlen($prefix)) : '';
    if (!skin_is_install_file($suffix)) {
        skin_miss();
    }
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . skin_content_type($file));
header('X-Content-Type-Options: nosniff');
if ($publicInstall && $ext === 'js') {
    header('Cache-Control: no-cache');
} elseif ($publicInstall) {
    header('Cache-Control: public, max-age=3600');
} elseif ($ext === 'html') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
} else {
    header('Cache-Control: private, no-cache');
}
readfile($file);
