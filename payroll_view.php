<?php
// E:\PAYROLL\payroll_view.php
// Printable weekly payroll report, mirroring the source spreadsheet layout.
// Three modes: (1) no params → site selector / week picker, (2) ?site_id=X → week picker for that site,
// (3) ?payroll_id=X → printable report.

require_once __DIR__ . '/config/session.php';
payroll_session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/config/DBpayroll.php';

$page_title = 'Payroll Report';
$active_page = 'payroll';

$payroll_id = (int)($_GET['payroll_id'] ?? 0);
$site_id = (int)($_GET['site_id'] ?? 0);

$show_picker = $payroll_id === 0 && $site_id > 0;

try {
    if ($show_picker) {
        // Just the site is given; list that site's payroll weeks as a picker
        $payrolls = dbGetPayrolls($site_id);
    } elseif ($payroll_id > 0) {
        $payroll = dbGetPayroll($payroll_id);
    } else {
        $payrolls = [];
    }
} catch (PDOException $e) {
    $flash = ['danger', 'Could not load payroll data. Check the database connection and try again.'];
    $_SESSION['flash'] = $flash;
    $redirect = $site_id ? 'payroll_view.php?site_id=' . $site_id : 'payroll_view.php';
    header('Location: ' . $redirect);
    exit();
}

// ---------------------------------------------------------------------------
// Mode variables: only defined for the active mode; others stay null/empty
// ---------------------------------------------------------------------------
$mode = null; // 'picker-site', 'picker-week', or 'report'
$site = null;
$payroll = null;
$entries = [];
$totals = [
    'payroll_total' => 0,
    'budget'        => 0,
    'site_deduction'=> 0,
    'add_expenses'  => 0,
    'net'           => 0,
];
$prev_start = '';
$prev_end = '';

if ($show_picker) {
    // Mode: site chosen → show week picker
    $mode = 'picker-week';
    $site = dbGetSite($site_id);
    // $payrolls already loaded above
} elseif ($payroll_id > 0) {
    // Mode: report
    $mode = 'report';
    try {
        $payroll = dbGetPayroll($payroll_id);
    } catch (PDOException $e) {
        $flash = ['danger', 'Could not load payroll report.'];
        $_SESSION['flash'] = $flash;
        header('Location: ' . ($site_id ? 'payroll_view.php?site_id=' . $site_id : 'payroll_view.php'));
        exit();
    }
    $site = dbGetSite((int)$payroll['site_id']);
    $entries = prWithCalc(dbGetPayrollEntries($payroll_id));
    $totals = prPayrollTotals($entries, $payroll);
    $prev_start = date('Y-m-d', strtotime($payroll['week_start'] . ' -7 days'));
    $prev_end   = date('Y-m-d', strtotime($payroll['week_end'] . ' -7 days'));
} else {
    // Mode: no params → show site selector first
    $mode = 'picker-site';
}

// Flash messages (if any) — cleared after display
$flash = $_SESSION['flash'] ?? [];
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
            <?php if ($mode === 'picker-site'): ?>
                <!-- First visit: show site selector to choose a site -->
                <div class="p-4 mb-4 border rounded">
                    <h4><i class="bi bi-building"></i> Select a Site</h4>
                    <p class="text-muted mb-3">Choose a site to view its payroll report.</p>
                    <div class="row">
                        <?php foreach ($sites_dropdown as $s): ?>
                            <div class="col-12 col-sm-6 col-md-4 mb-2">
                                <div class="border rounded p-3 hover-lift" style="min-height: 140px;">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?php echo htmlspecialchars($s['name']); ?></strong>
                                            <div class="text-muted small">Workers: <?php echo (int)$s['worker_count']; ?></div>
                                            <div class="text-muted small">Payroll weeks: <?php echo (int)$s['payroll_count']; ?></div>
                                        </div>
                                        <a href="payroll_view.php?site_id=<?php echo (int)$s['id']; ?>"
                                           class="btn btn-sm btn-primary stretched-link">
                                            <i class="bi bi-arrow-right-circle"></i> Select
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($mode === 'picker-week'): ?>
                <!-- Site chosen, now choose a week -->
                <div class="p-4 mb-4 border rounded">
                    <h4><i class="bi bi-building"></i> <?php echo htmlspecialchars($site['name']); ?> — Select Week</h4>
                    <p class="text-muted mb-3">Choose a payroll week to view its report.</p>
                    <div class="mb-3">
                        <select class="form-select form-select-sm" id="weekSelect" aria-label="Select week">
                            <option value="">Select a week...</option>
                            <?php foreach ($payrolls as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"
                                    <?php echo ((int)$_GET['payroll_id'] ?? 0) === (int)$p['id'] ? 'selected' : ''; ?>>
                                    <?php echo prDate($p['week_start']) . ' - ' . prDate($p['week_end']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" onclick="
                            var val = document.getElementById('weekSelect').value;
                            if (val) { window.location.href = 'payroll_view.php?payroll_id=' + val; }
                        ">
                            Go
                        </button>
                        <a href="payrolls.php?site_id=<?php echo $site_id; ?>" class="btn btn-link text-muted">Cancel</a>
                    </div>
                </div>
            <?php elseif ($mode === 'report'): ?>
                <!-- Full printable report -->
                <div class="no-print d-flex justify-content-between align-items-center mb-3">
                    <a href="payroll_form.php?payroll_id=<?php echo $payroll_id; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Edit Entries
                    </a>
                    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print / Save PDF
                    </button>
                </div>

                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                        <div>
                            <h4 class="mb-0"><?php echo htmlspecialchars($site['name']); ?></h4>
                            <div class="text-muted small">WEEKLY PAYROLL</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-semibold">
                                ATTENDANCE: <?php echo date('F j', strtotime($payroll['week_start'])); ?> -
                                <?php echo date('F j, Y', strtotime($payroll['week_end'])); ?>
                            </div>
                            <div class="text-muted small">BALI BINYE NA ENGR: <strong><?php echo prMoney($totals['budget']); ?></strong></div>
                            <div class="text-muted small" style="font-size:0.75em">* OT hrs = the PREVIOUS week's DTR OT (Sun-Sat), paid on this payroll. This week's OT (incl. SATURDAY) is paid on the NEXT payroll.</div>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small mb-3" role="alert">
                        <i class="bi bi-info-circle"></i> <strong>OT HRS*</strong> came from the <strong>PREVIOUS week's</strong> DTR
                        (<strong>Sun <?php echo prDate($prev_start); ?> - Sat <?php echo prDate($prev_end); ?></strong>) and are paid
                        on <strong>this</strong> payroll. OT recorded <strong>this</strong> week - including <strong>next SATURDAY</strong> -
                        will be paid on the <strong>NEXT</strong> week's payroll.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>NAME</th>
                                    <th>POSITION</th>
                                    <th class="text-end">RATE</th>
                                    <th class="text-center">DAYS</th>
                                    <th class="text-end">BASIC PAY</th>
                                    <th class="text-center">OT HRS*</th>
                                    <th class="text-end">OT RATE</th>
                                    <th class="text-end">OT PAY</th>
                                    <th class="text-end">TOTAL</th>
                                    <th class="text-end">PER. CASH ADV.</th>
                                    <th class="text-end">CASH ADV.</th>
                                    <th class="text-end">INCOME</th>
                                    <th class="text-end">NET</th>
                                    <?php foreach ($day_labels as $dl): ?>
                                        <th class="text-center"><?php echo $dl; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$entries): ?>
                                    <tr>
                                        <td colspan="21" class="text-center text-muted">No entries yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $i = 1; foreach ($entries as $e): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $i++; ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($e['name']); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($e['position'] ?: '-'); ?></td>
                                            <td class="text-end"><?php echo prMoney($e['rate']); ?></td>
                                            <td class="text-center"><?php echo $e['days_worked']; ?></td>
                                            <td class="text-end"><?php echo prMoney($e['basic']); ?></td>
                                            <td class="text-center"><?php echo $e['ot_hours']; ?></td>
                                            <td class="text-end"><?php echo prMoney($e['ot_rate']); ?></td>
                                            <td class="text-end"><?php echo prMoney($e['ot_pay']); ?></td>
                                            <td class="text-end fw-semibold"><?php echo prMoney($e['gross']); ?></td>
                                            <td class="text-end"><?php echo prMoney($e['personal_cash_advance']); ?></td>
                                            <td class="text-end"><?php echo prMoney($e['cash_advance']); ?></td>
                                            <td class="text-end"><?php echo prMoney($e['gross'] - $e['cash_advance'] - $e['personal_cash_advance']); ?></td>
                                            <td class="text-end fw-semibold"><?php echo prMoney($e['net']); ?></td>
                                            <?php
                                                $att = str_split(prNormAtt($e['attendance'] ?? ''));
                                                for ($d = 0; $d < 7; $d++) {
                                                    $code = htmlspecialchars($att[$d] ?? '-');
                                                    echo '<td class="text-center small py-1">' . $code . '</td>';
                                                }
                                            ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row justify-content-end mt-3">
                        <div class="col-md-5">
                            <table class="table table-bordered table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <th class="table-light">TOTAL PAYROLL</th>
                                        <td class="text-end fw-semibold"><?php echo prMoney($totals['payroll_total']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="table-light">CASH ADVANCE (BALI BINYE)</th>
                                        <td class="text-end">- <?php echo prMoney($totals['budget']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="table-light">DEDUCTION</th>
                                        <td class="text-end">- <?php echo prMoney($totals['site_deduction']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="table-light">ADD. EXPENSES</th>
                                        <td class="text-end">+ <?php echo prMoney($totals['add_expenses']); ?></td>
                                    </tr>
                                    <tr class="table-dark">
                                        <th>TOTAL TOTAL</th>
                                        <td class="text-end fw-bold"><?php echo prMoney($totals['net']); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
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