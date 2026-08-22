<?php
// E:\PAYROLL\payroll_entries.php
// Entry: shows all sites with the latest 5 payroll weeks each (entry dates only, so it wont all load up).

require_once __DIR__ . '/config/session.php';
payroll_session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/config/DBpayroll.php';
require_once __DIR__ . '/config/actions.php';

$page_title = 'Payroll Entry';
$active_page = 'payroll';

$is_admin = currentUserRole() === 'admin';

try {
    $sites = dbSitesWithLatestPayroll();
} catch (PDOException $e) {
    $sites = [];
    $flash = ['danger', 'Could not load sites. Check the database connection and try again.'];
    $_SESSION['flash'] = $flash;
    header('Location: dashboard.php');
    exit();
}

// Gather per-site latest 5 payroll weeks with entry counts
$site_payrolls = [];
foreach ($sites as $s) {
    try {
        $payrolls = dbGetPayrolls($s['id']);
        // Sort desc by week_start, take first 5
        usort($payrolls, function($a, $b) {
            return strtotime($b['week_start']) <=> strtotime($a['week_start']);
        });
        $limited = array_slice($payrolls, 0, 5);
        $site_payrolls[$s['id']] = [
            'site'    => $s,
            'payrolls'=> $limited,
        ];
    } catch (PDOException $e) {
        $site_payrolls[$s['id']] = ['site' => $s, 'payrolls' => []];
    }
}
?>
<?php
// Flash messages from session (set by other pages via $_SESSION['flash'])
$flash = $_SESSION['flash'] ?? [];
// Clear so they don't persist
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Payroll System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"
        integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
    <link href="assets/css/app.css?v=12" rel="stylesheet">
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
                <?php foreach ($app_nav as $nav): ?>
                    <?php if ($nav[3] === '#'): ?>
                        <div class="dropdown">
                            <a class="sidebar-link dropdown-toggle <?php echo $active_page === 'payroll' ? 'active' : ''; ?>"
                                href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi <?php echo $nav[2]; ?>"></i><span><?php echo $nav[1]; ?></span>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="payroll_entries.php"><i class="bi bi-pencil-square"></i> Entry</a></li>
                                <li><a class="dropdown-item" href="payroll_view.php"><i class="bi bi-eye"></i> View</a></li>
                                <li><a class="dropdown-item" href="payrolls.php"><i class="bi bi-plus-circle"></i> Add Payroll</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="ca_history.php"><i class="bi bi-clock-history"></i> CA History</a></li>
                            </ul>
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
                $sites_dropdown = dbSitesWithLatestPayroll();
                if (!empty($sites_dropdown) && in_array($active_page, ['payroll', 'payslip', 'dtr', 'sites'], true)):
                    $sel_site_id = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
                    if ($sel_site_id === 0 && !empty($_SESSION['last_site_id'])) {
                        $sel_site_id = (int)$_SESSION['last_site_id'];
                    }
                    $sel_site = null;
                    if ($sel_site_id) {
                        foreach ($sites_dropdown as $s) { if ((int)$s['id'] === $sel_site_id) { $sel_site = $s; break; } }
                    }
                ?>
                <div class="topbar-site-selector ms-3 d-flex align-items-center gap-2" style="max-width: 420px;">
                    <label for="topbarSiteSelect" class="visually-hidden">Select site</label>
                    <select class="form-select form-select-sm" id="topbarSiteSelect" aria-label="Select site">
                        <option value="">Select a site...</option>
                        <?php foreach ($sites_dropdown as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>" <?php echo $sel_site_id === (int)$s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['name']); ?>
                                <?php if (!empty($s['latest_payroll_id'])): ?>
                                    (<?php echo prDate($s['latest_week_start']).'..'.prDate($s['latest_week_end']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($sel_site): ?>
                    <div class="topbar-quick-actions d-flex gap-1 ms-2" style="min-width: 260px;">
                        <a class="btn btn-sm btn-outline-primary" href="payrolls.php?site_id=<?php echo $sel_site['id']; ?>" title="All weeks for <?php echo htmlspecialchars($sel_site['name']); ?>">
                            <i class="bi bi-grid-1x2"></i> Weeks
                        </a>
                        <?php if ($sel_site['latest_payroll_id']): ?>
                        <a class="btn btn-sm btn-outline-primary" href="payroll_form.php?payroll_id=<?php echo (int)$sel_site['latest_payroll_id']; ?>" title="Edit entries for latest week">
                            <i class="bi bi-pencil-square"></i> Edit
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="payroll_view.php?payroll_id=<?php echo (int)$sel_site['latest_payroll_id']; ?>" title="View / Print latest week">
                            <i class="bi bi-eye"></i> View
                        </a>
                        <?php else: ?>
                        <span class="btn btn-sm btn-outline-secondary disabled" title="No payroll weeks yet">
                            <i class="bi bi-plus-circle"></i> Add Week
                        </span>
                        <?php endif; ?>
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
                <div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h3><i class="bi bi-pencil-square"></i> Payroll Entry</h3>
                        <small class="text-muted">Select a site to view its recent payroll weeks (last 5).</small>
                    </div>
                </div>

                <?php foreach ($flash as $f): ?>
                    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
                        <?php echo $f[1]; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($sites)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        No sites found. <a href="sites.php">Add a site</a> to get started.
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($site_payrolls as $sp): ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="border rounded p-3 mb-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <a href="payrolls.php?site_id=<?php echo (int)$sp['site']['id']; ?>" class="fw-semibold text-decoration-none">
                                                <i class="bi bi-building"></i> <?php echo htmlspecialchars($sp['site']['name']); ?>
                                            </a>
                                            <div class="text-muted small">
                                                <?php echo (int)$sp['site']['worker_count']; ?> workers
                                            </div>
                                        </div>
                                        <span class="badge text-bg-light">
                                            <?php echo count($sp['payrolls']); ?> weeks (of 5)
                                        </span>
                                    </div>

                                    <?php if (empty($sp['payrolls'])): ?>
                                        <div class="text-muted small my-2">No payroll weeks yet.</div>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush mb-3">
                                            <?php foreach ($sp['payrolls'] as $p): ?>
                                                <a href="payroll_form.php?payroll_id=<?php echo (int)$p['id']; ?>"
                                                   class="list-group-item list-group-item-action small py-2">
                                                    <div class="d-flex w-100 justify-content-between">
                                                        <div>
                                                            <strong><?php echo prDate($p['week_start']) . ' - ' . prDate($p['week_end']); ?></strong>
                                                            <div class="text-muted small">Entries: <?php echo (int)$p['entry_count']; ?> / <?php echo (int)$sp['site']['worker_count']; ?></div>
                                                        </div>
                                                        <span class="text-muted small">
                                                            <?php echo $is_admin ? 'Edit' : 'View'; ?>
                                                        </span>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                        <a href="payrolls.php?site_id=<?php echo (int)$sp['site']['id']; ?>"
                                           class="text-muted small text-decoration-none mt-2 d-block">
                                            <i class="bi bi-arrow-down-circle me-1"></i> View all weeks for this site
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Highlight the active dropdown item based on current page
                var path = window.location.pathname;
                document.querySelectorAll('.sidebar-nav .dropdown-menu .dropdown-item').forEach(function (link) {
                    if (link.getAttribute('href') === path) {
                        link.classList.add('active');
                    }
                });
            });
        </script>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/bootstrap.bundle.min.js"
        integrity="sha384-ENjdO4Dr2bIBQbBj8+gx50YAAA0QQ7JWZQnB0ZPCWaF2e5dDj8e2IOkoksXzJz6"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>