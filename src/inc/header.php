<?php
// E:\PAYROLL\inc\header.php
// Shared authenticated layout: sidebar + content shell.
// Set $page_title and $active_page ('dashboard' | 'sites' | 'dtr' | 'users')
// before requiring this file.

require_once __DIR__ . '/../config/session.php';

if (session_status() === PHP_SESSION_NONE) {
    payroll_session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$page_title = isset($page_title) ? $page_title : 'Payroll System';
$active_page = isset($active_page) ? $active_page : '';

// Remember selected site in session
if (isset($_GET['site_id'])) {
    $_SESSION['last_site_id'] = (int)$_GET['site_id'];
} elseif (!isset($_SESSION['last_site_id'])) {
    $_SESSION['last_site_id'] = null;
}

require_once __DIR__ . '/../config/DBpayroll.php';

// Self-heal older databases that predate the personal_cash_advance column.
dbEnsurePayrollSchema();

// Light per-session CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
        http_response_code(419);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Request Expired</title></head>'
            . '<body style="font-family:sans-serif;background:#f0f2f5;margin:0;padding:40px;text-align:center">'
            . '<div style="background:#fff;max-width:420px;margin:60px auto;padding:32px;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.08)">'
            . '<h3 style="margin-top:0">Session expired or invalid request token.</h3>'
            . '<p>Please reload the page and try again.</p>'
            . '<a href="javascript:location.reload()" style="display:inline-block;padding:10px 22px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:10px;text-decoration:none;font-weight:600">Reload page</a>'
            . '</div></body></html>';
        exit();
    }
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">';
}

$app_nav = [
    ['dashboard', 'Dashboard', 'bi-speedometer2', 'dashboard.php'],
    ['sites', 'Sites', 'bi-geo-alt', 'sites.php'],
    ['payroll', 'Payroll', 'bi-cash-stack', '#'],
    ['payslip', 'Payslip', 'bi-receipt-cutoff', 'payslip.php'],
    ['dtr', 'DTR', 'bi-clipboard-check', 'dtr.php'],
];
if (currentUserRole() === 'admin') {
    $app_nav[] = ['users', 'Users', 'bi-people-fill', 'users.php'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Payroll System</title>
    <script>
        /* Apply the saved theme BEFORE first paint - kills the white flash. */
        (function () {
            try {
                var t = localStorage.getItem('payroll-theme');
                if (t !== 'light' && t !== 'dark') {
                    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
                }
                var d = document.documentElement;
                d.setAttribute('data-theme', t);
                d.setAttribute('data-bs-theme', t);
            } catch (e) { /* private mode etc. */ }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"
        integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
    <link href="assets/css/app.css?v=17" rel="stylesheet">
</head>

<body>
    <div class="app-layout">
        <aside class="sidebar" id="sidebar" aria-label="Main navigation">
            <div class="sidebar-brand">
                <div class="brand-icon"><i class="bi bi-shield-lock"></i></div>
                <div>
                    <div class="brand-name">Payroll System</div>
                    <div class="brand-sub">Signed in as <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                <?php $is_admin_nav = currentUserRole() === 'admin'; ?>
                <?php foreach ($app_nav as $nav): ?>
                    <?php if ($nav[3] === '#'): ?>
                        <a class="sidebar-link <?php echo $active_page === 'payroll' ? 'active' : ''; ?>"
                            href="#payrollSubnav" data-bs-toggle="collapse" role="button"
                            aria-expanded="<?php echo $active_page === 'payroll' ? 'true' : 'false'; ?>"
                            aria-controls="payrollSubnav">
                            <i class="bi <?php echo $nav[2]; ?>"></i><span><?php echo $nav[1]; ?></span>
                        </a>
                        <div class="collapse<?php echo $active_page === 'payroll' ? ' show' : ''; ?>" id="payrollSubnav">
                            <div class="sidebar-subnav">
                                <?php if ($is_admin_nav): ?>
                                    <a class="sidebar-sublink<?php echo $current_page === 'payroll_entries.php' ? ' active' : ''; ?>"
                                        href="payroll_entries.php"><i class="bi bi-pencil-square"></i> Entry</a>
                                <?php endif; ?>
                                <a class="sidebar-sublink<?php echo $current_page === 'payroll_view.php' ? ' active' : ''; ?>"
                                    href="payroll_view.php"><i class="bi bi-eye"></i> View</a>
                                <?php if ($is_admin_nav): ?>
                                    <a class="sidebar-sublink<?php echo $current_page === 'payrolls.php' ? ' active' : ''; ?>"
                                        href="payrolls.php"><i class="bi bi-plus-circle"></i> Add Payroll</a>
                                <?php endif; ?>
                                <a class="sidebar-sublink<?php echo $current_page === 'ca_history.php' ? ' active' : ''; ?>"
                                    href="ca_history.php"><i class="bi bi-clock-history"></i> CA History</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a class="sidebar-link <?php echo $active_page === $nav[0] ? 'active' : ''; ?>"
                            href="<?php echo $nav[3]; ?>">
                            <i class="bi <?php echo $nav[2]; ?>"></i><span><?php echo $nav[1]; ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-footer">
                <button type="button" class="sidebar-action" id="themeToggle" title="Toggle dark / light theme">
                    <i class="bi bi-circle-half" id="themeIcon"></i><span>Theme</span>
                </button>
                <a class="sidebar-action" href="logout.php" title="Sign out">
                    <i class="bi bi-box-arrow-right"></i><span>Logout</span>
                </a>
            </div>
        </aside>

        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <div class="app-main">
            <header class="app-topbar no-print">
                <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle" type="button"
                    aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <span class="app-topbar-title"><?php echo htmlspecialchars($page_title); ?></span>
                <?php
                // Site selector with quick actions for payroll pages
                $sites = dbSitesWithLatestPayroll();
                if (!empty($sites) && in_array($active_page, ['payroll', 'payslip', 'dtr', 'sites'], true)):
                    $sel_site_id = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
                    if ($sel_site_id === 0 && !empty($_SESSION['last_site_id'])) {
                        $sel_site_id = (int)$_SESSION['last_site_id'];
                    }
                    $sel_site = null;
                    if ($sel_site_id) {
                        foreach ($sites as $s) { if ((int)$s['id'] === $sel_site_id) { $sel_site = $s; break; } }
                    }
                ?>
                <div class="topbar-site-selector d-flex align-items-center gap-2">
                    <label for="topbarSiteSelect" class="visually-hidden">Select site</label>
                    <select class="form-select form-select-sm" id="topbarSiteSelect" aria-label="Select site">
                        <option value="">Select a site...</option>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>" <?php echo $sel_site_id === (int)$s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['name']); ?>
                                <?php if (!empty($s['latest_payroll_id'])): ?>
                                    (<?php echo prDate($s['latest_week_start']).'..'.prDate($s['latest_week_end']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($sel_site): ?>
                    <div class="topbar-quick-actions d-flex gap-1 ms-2">
                        <a class="btn btn-sm btn-outline-primary" href="payrolls.php?site_id=<?php echo $sel_site['id']; ?>" title="All weeks for <?php echo htmlspecialchars($sel_site['name']); ?>">
                            <i class="bi bi-grid-1x2"></i> <span class="btn-label">Weeks</span>
                        </a>
                        <?php if ($is_admin_nav && $sel_site['latest_payroll_id']): ?>
                        <a class="btn btn-sm btn-outline-primary" href="payroll_form.php?payroll_id=<?php echo (int)$sel_site['latest_payroll_id']; ?>" title="Edit entries for latest week">
                            <i class="bi bi-pencil-square"></i> <span class="btn-label">Edit</span>
                        </a>
                        <?php endif; ?>
                        <?php if ($sel_site['latest_payroll_id']): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="payroll_view.php?payroll_id=<?php echo (int)$sel_site['latest_payroll_id']; ?>" title="View / Print latest week">
                            <i class="bi bi-eye"></i> <span class="btn-label">View</span>
                        </a>
                        <?php else: ?>
                        <span class="btn btn-sm btn-outline-secondary disabled" title="No payroll weeks yet">
                            <i class="bi bi-plus-circle"></i> <span class="btn-label">Add Week</span>
                        </span>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-success" href="ca_history.php" title="Cash advance histories">
                            <i class="bi bi-clock-history"></i> <span class="btn-label">CA</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" name="site_id" id="topbarSiteId" value="<?php echo $sel_site_id; ?>">
                </div>
                <?php endif; ?>
                <script>
                    document.getElementById('topbarSiteSelect')?.addEventListener('change', function() {
                        var url = new URL(window.location.href);
                        if (this.value) {
                            url.searchParams.set('site_id', this.value);
                        } else {
                            url.searchParams.delete('site_id');
                        }
                        window.location.href = url.toString();
                    });
                </script>
            </header>

            <main id="app-content" class="app-content">
