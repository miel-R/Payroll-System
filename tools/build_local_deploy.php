<?php
// E:\PAYROLL\tools\build_local_deploy.php
//
// Regenerates the self-contained local/self-hosted deployment folder
// (local-deploy/) from the main app. Run from the repo root:
//
//     php tools/build_local_deploy.php
//
// The local-deploy folder is committed so it can be grabbed directly, and it is
// excluded from Vercel deploys via .vercelignore. Re-run this script after any
// code change to refresh the copy.

$root = realpath(__DIR__ . '/..');
$dest = $root . '/local-deploy';

$files = [
    // Pages
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
    'create_user.php',
    'ajax.php',
    // Config
    'config/DBconnect.php',
    'config/DBgetPDO.php',
    'config/DBpayroll.php',
    'config/PDF.php',
    'config/session.php',
    'config/actions.php',
    'config/db_credentials.php.example',
    // Layout
    'inc/header.php',
    'inc/footer.php',
    // Assets (Bootstrap comes from the CDN)
    'assets/css/app.css',
    'assets/css/index_style.css',
    'assets/js/app.js',
    // Schema
    'database/schema.sql',
    // Composer (vendor/ is copied separately below)
    'composer.json',
    'composer.lock',
];

$templates = [
    'tools/templates/local_deploy/README.md' => 'README.md',
    'tools/templates/local_deploy/.htaccess' => '.htaccess',
];

// Wipe the previous build (only if it is a directory under the repo root).
if (is_dir($dest) && strpos(realpath($dest), $root . DIRECTORY_SEPARATOR) === 0) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dest);
}

mkdir($dest, 0777, true);

$missing = [];
$copied = 0;

foreach (array_merge($files, array_keys($templates)) as $rel) {
    $src = $root . '/' . $rel;
    if (!is_file($src)) {
        $missing[] = $rel;
        continue;
    }
    $out = array_key_exists($rel, $templates) ? $templates[$rel] : $rel;
    $target = $dest . '/' . $out;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }
    copy($src, $target);
    $copied++;
}

if ($missing) {
    fwrite(STDERR, "Missing source files:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}

// Copy the whole vendor/ tree (dompdf + deps) so the local deploy is self-contained.
$vendor = $root . '/vendor';
if (is_dir($vendor)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        $rel = substr($entry->getPathname(), strlen($root) + 1);
        $target = $dest . '/' . $rel;
        if ($entry->isDir()) {
            mkdir($target, 0777, true);
        } else {
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            copy($entry->getPathname(), $target);
            $copied++;
        }
    }
} else {
    fwrite(STDERR, "WARNING: no vendor/ directory; run 'php composer.phar install' first\n");
}

echo "Built local-deploy/ ($copied files)\n";
echo "Run: php -S localhost:8000 -t local-deploy\n";
