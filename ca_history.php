<?php
// E:\PAYROLL\ca_history.php
// Dedicated Cash Advance History page — Personal CA ledger, Repaid Per Week, and
// regular Cash Advance History. Extracted from payroll.php so it is no longer
// embedded in the payroll hub.

require_once __DIR__ . '/config/session.php';
payroll_session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/config/DBpayroll.php';
require_once __DIR__ . '/config/actions.php';

$page_title = 'Cash Advance History';
$active_page = 'payroll';

$is_admin = currentUserRole() === 'admin';

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = run_action((string)($_POST['action'] ?? ''), [
        'post'     => $_POST,
        'is_admin' => $is_admin,
        'user_id'  => (int)($_SESSION['user_id'] ?? 0),
        'site_id'  => (int)($_POST['site_id'] ?? 0),
    ]);
    if ($res['msg'] !== '') {
        $flash[] = [$res['type'], htmlspecialchars($res['msg'])];
    }
    if ($res['render'] === 'redirect' && !empty($res['data']['url'])) {
        echo '<script>setTimeout(function(){window.location.replace(' . json_encode($res['data']['url']) . ');}, 800);</script>';
    }
}

try {
    $pca_ledger = dbPersonalCaHistoryAll();
    $pca_recovery = dbPersonalCaRecoveryHistory();
    $ca_history = dbCashAdvanceHistory();
} catch (PDOException $e) {
    $pca_ledger = [];
    $pca_recovery = [];
    $ca_history = [];
    $flash[] = ['danger', 'Could not load cash advance data. Check the database connection and try again.'];
}

// Week pairs for filters
$ca_week_pairs = [];
foreach ($ca_history as $c) {
    $ca_week_pairs[(string)$c['week_start']] = (string)$c['week_end'];
}
$pca_week_pairs = [];
foreach ($pca_recovery as $r) {
    $pca_week_pairs[(string)$r['week_start']] = (string)$r['week_end'];
}
$default_ca_week = $ca_week_pairs ? max(array_keys($ca_week_pairs)) : '';
$default_pca_week = $pca_week_pairs ? max(array_keys($pca_week_pairs)) : '';
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
                    <h3><i class="bi bi-clock-history"></i> Cash Advance History</h3>
                    <small class="text-muted">Personal CA ledger, repayments, and weekly cash advances.</small>
                </div>
            </div>

            <?php foreach ($flash as $f): ?>
                <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
                    <?php echo $f[1]; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>

            <!-- ======= Personal Cash Advance History (Advances Given) ======= -->
            <div class="content-card mb-4">
                <h4><i class="bi bi-arrow-down-circle"></i> Personal Cash Advance History</h4>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="text-muted"><i class="bi bi-arrow-down-circle"></i> Advances Given (ledger)</h6>
                        <?php if (!$pca_ledger): ?>
                            <p class="text-muted small mb-0">No personal cash advances recorded yet.</p>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <label class="form-label small mb-0" for="pcaStatusFilter">Status</label>
                                <select id="pcaStatusFilter" class="form-select form-select-sm w-auto js-table-filter"
                                    data-filter-table="pcaLedgerTable" data-filter-key="status">
                                    <option value="">All</option>
                                    <option value="pending">Pending</option>
                                    <option value="done">Paid / Done</option>
                                </select>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-sm" id="pcaLedgerTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Worker</th>
                                            <th>Site</th>
                                            <th class="text-end">Given</th>
                                            <th class="text-end">Recovered</th>
                                            <th class="text-end">Balance</th>
                                            <th>Status</th>
                                            <?php if ($is_admin): ?><th class="text-end"></th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pca_ledger as $p): ?>
                                            <?php $done = $p['status'] === 'done'; ?>
                                            <tr data-status="<?php echo $done ? 'done' : 'pending'; ?>">
                                                <td><?php echo prDate($p['advance_date']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($p['worker_name']); ?>
                                                    <?php if ($p['note'] !== ''): ?>
                                                        <div class="small text-muted"><?php echo htmlspecialchars($p['note']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($p['site_name']); ?></td>
                                                <td class="text-end"><?php echo prMoney($p['amount']); ?></td>
                                                <td class="text-end"><?php echo prMoney($p['recovered']); ?></td>
                                                <td class="text-end"><?php echo prMoney($p['balance']); ?></td>
                                                <td>
                                                    <?php if ($done): ?>
                                                        <span class="badge text-bg-success">Paid</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-warning">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($is_admin): ?>
                                                    <td class="text-end">
                                                        <form method="POST" action="ca_history.php" class="d-inline"
                                                            data-api data-confirm="Delete this personal cash advance entry?">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="pca.delete">
                                                            <input type="hidden" name="pca_id" value="<?php echo (int)$p['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr data-filter-empty style="display:none">
                                            <td colspan="99" class="text-center text-muted py-3">No personal cash advances match this status.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-6">
                        <h6 class="text-muted"><i class="bi bi-arrow-up-circle"></i> Repaid Per Week</h6>
                        <?php if (!$pca_recovery): ?>
                            <p class="text-muted small mb-0">No repayments recorded yet.</p>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <label class="form-label small mb-0" for="pcaWeekFilter">Week</label>
                                <select id="pcaWeekFilter" class="form-select form-select-sm w-auto js-table-filter"
                                    data-filter-table="pcaRepaidTable" data-filter-key="week">
                                    <option value="">All Weeks</option>
                                    <?php foreach ($pca_week_pairs as $ws => $we): ?>
                                        <option value="<?php echo htmlspecialchars($ws); ?>" <?php echo $ws === $default_pca_week ? 'selected' : ''; ?>>
                                            <?php echo prDate($ws) . ' - ' . prDate($we); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-sm" id="pcaRepaidTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Week</th>
                                            <th>Site</th>
                                            <th>Worker</th>
                                            <th class="text-end">Repaid</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pca_recovery as $r): ?>
                                            <tr data-week="<?php echo htmlspecialchars($r['week_start']); ?>">
                                                <td><?php echo prDate($r['week_start']) . ' - ' . prDate($r['week_end']); ?></td>
                                                <td><?php echo htmlspecialchars($r['site_name']); ?></td>
                                                <td><?php echo htmlspecialchars($r['worker_name']); ?></td>
                                                <td class="text-end"><?php echo prMoney($r['recovered']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr data-filter-empty style="display:none">
                                            <td colspan="99" class="text-center text-muted py-3">No repayments match this week.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ======= Cash Advance History (Weekly Deductions) ======= -->
            <div class="content-card">
                <h4><i class="bi bi-wallet2"></i> Cash Advance History</h4>
                <p class="text-muted small mb-3">The regular weekly cash advance deducted from each worker's pay (the "Cash Adv." entry).</p>
                <?php if (!$ca_history): ?>
                    <p class="text-muted mb-0">No cash advances recorded yet. Enter them in each week's Edit / Save Entries.</p>
                <?php else: ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <label class="form-label small mb-0" for="caWeekFilter">Week</label>
                        <select id="caWeekFilter" class="form-select form-select-sm w-auto js-table-filter"
                            data-filter-table="caHistoryTable" data-filter-key="week">
                            <option value="">All Weeks</option>
                            <?php foreach ($ca_week_pairs as $ws => $we): ?>
                                <option value="<?php echo htmlspecialchars($ws); ?>" <?php echo $ws === $default_ca_week ? 'selected' : ''; ?>>
                                    <?php echo prDate($ws) . ' - ' . prDate($we); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-sm" id="caHistoryTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Week</th>
                                    <th>Site</th>
                                    <th>Worker</th>
                                    <th class="text-end">Cash Advance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ca_history as $c): ?>
                                    <tr data-week="<?php echo htmlspecialchars($c['week_start']); ?>">
                                        <td><?php echo prDate($c['week_start']) . ' - ' . prDate($c['week_end']); ?></td>
                                        <td><?php echo htmlspecialchars($c['site_name']); ?></td>
                                        <td><?php echo htmlspecialchars($c['worker_name']); ?></td>
                                        <td class="text-end"><?php echo prMoney($c['cash_advance']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr data-filter-empty style="display:none">
                                    <td colspan="99" class="text-center text-muted py-3">No cash advances match this week.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/bootstrap.bundle.min.js"
        integrity="sha384-ENjdO4Dr2bIBQbBj8+gx50YAAA0QQ7JWZQnB0ZPCWaF2e5dDj8e2IOkoksXzJz6"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.js"></script>
    <script src="assets/js/app.js"></script>
</div>
</body>
</html>