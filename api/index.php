<?php
// E:\PAYROLL\api\index.php
// Vercel front controller (vercel-php runtime).
//
// Vercel only executes PHP files under api/. Page scripts live in public/
// (the webroot layout); this router dispatches page requests to them and
// serves public/assets/ itself. Only whitelisted pages are reachable.

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
if ($path === false || $path === null) {
    $path = '/';
}
$path = rawurldecode($path);
$path = '/' . ltrim($path, '/');

// ------------------------------------------------------------
// Static assets: serve directly from public/assets/ with a content type.
// ------------------------------------------------------------
if (strpos($path, '/assets/') === 0) {
    $file = realpath(__DIR__ . '/../public' . $path);
    $root = realpath(__DIR__ . '/../public/assets/');
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
    'payroll_entries.php',
    'ca_history.php',
];

$name = $path === '/' ? 'index.php' : basename($path);

if (!in_array($name, $allowed, true)) {
    http_response_code(404);
    echo 'Not found';
    exit();
}

$page = __DIR__ . '/../public/' . $name;
if (!is_file($page)) {
    http_response_code(404);
    echo 'Not found';
    exit();
}

require $page;
