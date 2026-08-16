<?php
// E:\PAYROLL\api\index.php
// Vercel front controller (vercel-php runtime).
//
// Vercel only executes PHP files under api/. All other files in the repo root
// are uploaded into the lambda, so this router dispatches page requests to the
// real page scripts (index.php, sites.php, ...) and serves static assets from
// assets/ itself. Only whitelisted pages are reachable.

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
if ($path === false || $path === null) {
    $path = '/';
}
$path = rawurldecode($path);
$path = '/' . ltrim($path, '/');

// ------------------------------------------------------------
// Static assets: serve directly from assets/ with a content type.
// ------------------------------------------------------------
if (strpos($path, '/assets/') === 0) {
    $file = realpath(__DIR__ . '/../' . $path);
    $root = realpath(__DIR__ . '/../assets/');
    if ($file === false || $root === false || strpos($file, $root) !== 0 || !is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        exit();
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'html'  => 'text/html; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
    ];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    exit();
}

// ------------------------------------------------------------
// Pages: dispatch only whitelisted scripts.
// ------------------------------------------------------------
$allowed = [
    'index.php',
    'dashboard.php',
    'sites.php',
    'site_workers.php',
    'payrolls.php',
    'payroll.php',
    'payroll_form.php',
    'payroll_view.php',
    'payslip.php',
    'dtr.php',
    'users.php',
    'logout.php',
    'contact.php',
    'ajax.php',
];

$name = $path === '/' ? 'index.php' : basename($path);

if (!in_array($name, $allowed, true)) {
    http_response_code(404);
    echo 'Not found';
    exit();
}

$page = __DIR__ . '/../' . $name;
if (!is_file($page)) {
    http_response_code(404);
    echo 'Not found';
    exit();
}

require $page;
