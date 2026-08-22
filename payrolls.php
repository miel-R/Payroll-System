<?php
// E:\PAYROLL\payrolls.php
// Weekly payroll periods for a site. Add, list and delete a week.

require_once __DIR__ . '/config/session.php';
payroll_session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/config/DBpayroll.php';
require_once __DIR__ . '/config/actions.php';

$page_title = 'Payrolls';
$active_page = 'payroll';

$is_admin = currentUserRole() === 'admin';

$site_id = (int)($_GET['site_id'] ?? 0);

$show_all_sites = $site_id === 0;

if ($show_all_sites) {
    try {
        $sites = dbSitesWithLatestPayroll();
    } catch (PDOException $e) {
        $sites = [];
        $flash = ['danger', 'Could not load sites.'];
        $_SESSION['flash'] = $flash;
        header('Location: dashboard.php');
        exit();
    }
    require_once __DIR__ . '/inc/header.php';
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
                    <h3><i class="bi bi-cash-stack"></i> Payroll Hub</h3>
                    <small class="text-muted">Select a site to manage its payroll weeks.</small>
                </div>
            </div>

            <?php $flash = $_SESSION['flash'] ?? [];
            foreach ($flash as $f): ?>
                <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
                    <?php echo $f[1]; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; unset($_SESSION['flash']); ?>

            <div class="row g-4">
                <?php foreach ($sites as $s): ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="border rounded p-3 mb-3 h-100">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>" class="fw-semibold text-decoration-none">
                                        <i class="bi bi-building"></i> <?php echo htmlspecialchars($s['name']); ?>
                                    </a>
                                    <div class="text-muted small">
                                        <?php echo (int)$s['worker_count']; ?> workers &middot;
                                        <?php echo (int)$s['payroll_count']; ?> payroll weeks
                                    </div>
                                </div>
                                <span class="badge text-bg-light">
                                    <?php echo (int)$s['latest_entries']; ?>/<?php echo (int)$s['worker_count']; ?> entries
                                </span>
                            </div>
                            <div class="row text-muted small text-center my-2">
                                <?php if ($s['latest_payroll_id']): ?>
                                    <div class="col-6">
                                        <div>Latest week</div>
                                        <strong><?php echo prDate($s['latest_week_start']) . ' - ' . prDate($s['latest_week_end']); ?></strong>
                                    </div>
                                    <div class="col-3">
                                        <div>Payroll</div>
                                        <strong><?php echo prMoney($s['latest_total']); ?></strong>
                                    </div>
                                    <div class="col-3">
                                        <div>Cash Adv.</div>
                                        <strong><?php echo prMoney($s['latest_budget']); ?></strong>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small my-2">No payroll weeks yet.</div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 mt-auto">
                                <?php if ($is_admin): ?>
                                    <button type="button" class="btn btn-sm btn-primary flex-fill" data-bs-toggle="modal"
                                        data-bs-target="#addPayrollModal" data-site="<?php echo (int)$s['id']; ?>"
                                        data-site-name="<?php echo htmlspecialchars($s['name']); ?>">
                                        <i class="bi bi-plus-circle"></i> Add Payroll Week
                                    </button>
                                <?php endif; ?>
                                <?php if ($s['latest_payroll_id']): ?>
                                    <a href="payroll_form.php?payroll_id=<?php echo (int)$s['latest_payroll_id']; ?>"
                                        class="btn btn-sm btn-outline-primary flex-fill" title="Edit / Save Entries">
                                        <i class="bi bi-pencil-square"></i> Edit / Save Entries
                                    </a>
                                <?php else: ?>
                                    <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>"
                                        class="btn btn-sm btn-outline-secondary flex-fill" title="Add a week first">
                                        <i class="bi bi-pencil-square"></i> Add a week first
                                    </a>
                                <?php endif; ?>
                                <?php if ($s['latest_payroll_id']): ?>
                                    <a href="payroll_view.php?payroll_id=<?php echo (int)$s['latest_payroll_id']; ?>"
                                        class="btn btn-sm btn-outline-secondary flex-fill" title="View / Print">
                                        <i class="bi bi-eye"></i> View / Print
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
<?php
    exit();
}
require_once __DIR__ . '/inc/header.php';

require_once __DIR__ . '/inc/header.php';

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = run_action((string)($_POST['action'] ?? ''), [
        'post'     => $_POST,
        'is_admin' => $is_admin,
        'user_id'  => (int)($_SESSION['user_id'] ?? 0),
        'site_id'  => (int)($_POST['site_id'] ?? $site_id),
    ]);
    if ($res['msg'] !== '') {
        $flash[] = [$res['type'], htmlspecialchars($res['msg'])];
    }
    if ($res['render'] === 'redirect' && !empty($res['data']['url'])) {
        echo '<script>setTimeout(function(){window.location.replace(' . json_encode($res['data']['url']) . ');}, 800);</script>';
    }
    if ($res['render'] === 'pdf' && !empty($res['data']['pdf'])) {
        echo '<script>'
            . 'var b=' . json_encode($res['data']['pdf']) . ';'
            . 'var a=document.createElement("a");'
            . 'a.href=URL.createObjectURL(new Blob([Uint8Array.from(atob(b),function(c){return c.charCodeAt(0);})],{type:"application/pdf"}));'
            . 'a.download=' . json_encode($res['data']['filename'] ?? 'backup.pdf') . ';'
            . 'document.body.appendChild(a);a.click();'
            . 'setTimeout(function(){window.location.reload();},1400);'
            . '</script>';
    }
}

$payrolls = dbGetPayrolls($site_id);
?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <a href="site_workers.php?site_id=<?php echo (int)$site_id; ?>"
            class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left"></i> Workers</a>
        <h3><i class="bi bi-cash-stack"></i> <?php echo htmlspecialchars($site['name']); ?></h3>
        <small class="text-muted">One payroll per week. Budget = cash advance released to the engineer ("BALI BINYE").</small>
    </div>
    <?php if ($is_admin): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payrollModal">
            <i class="bi bi-plus-circle"></i> Add Payroll Week
        </button>
    <?php endif; ?>
</div>

<?php foreach ($flash as $f): ?>
    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
        <?php echo $f[1]; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="content-card">
    <h4><i class="bi bi-calendar-week"></i> Payroll Weeks (<?php echo count($payrolls); ?>)</h4>
    <?php if (!$payrolls): ?>
        <p class="text-muted mb-0">No payroll weeks yet.</p>
    <?php else: ?>
        <div class="table-responsive d-none d-lg-block">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Week</th>
                        <th class="text-center">Workers</th>
                        <th class="text-end">Payroll</th>
                        <th class="text-end">Budget</th>
                        <th class="text-end">Deduction</th>
                        <th class="text-end">Add. Exp.</th>
                        <th class="text-end">Net</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payrolls as $p):
                        $net = $p['payroll_total'] - $p['budget'] - $p['site_deduction'] + $p['add_expenses'];
                    ?>
                        <tr>
                            <td class="fw-semibold">
                                <?php echo prDate($p['week_start']) . ' - ' . prDate($p['week_end']); ?>
                            </td>
                            <td class="text-center"><?php echo (int)$p['entry_count']; ?></td>
                            <td class="text-end"><?php echo prMoney($p['payroll_total']); ?></td>
                            <td class="text-end"><?php echo prMoney($p['budget']); ?></td>
                            <td class="text-end"><?php echo prMoney($p['site_deduction']); ?></td>
                            <td class="text-end"><?php echo prMoney($p['add_expenses']); ?></td>
                            <td class="text-end fw-semibold"><?php echo prMoney($net); ?></td>
                            <td class="text-end">
                                <?php if ($is_admin): ?>
                                    <a href="payroll_form.php?payroll_id=<?php echo (int)$p['id']; ?>"
                                        class="btn btn-sm btn-outline-primary" title="Edit Entries">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="payroll_view.php?payroll_id=<?php echo (int)$p['id']; ?>"
                                    class="btn btn-sm btn-outline-secondary" title="View / Print">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($is_admin): ?>
                                    <form method="POST" action="payrolls.php?site_id=<?php echo (int)$site_id; ?>"
                                        class="d-inline" data-api data-confirm="Delete this payroll week?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="payroll.delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-lg-none">
            <?php foreach ($payrolls as $p):
                $net = $p['payroll_total'] - $p['budget'] - $p['site_deduction'] + $p['add_expenses'];
            ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-semibold">
                            <?php echo prDate($p['week_start']) . ' - ' . prDate($p['week_end']); ?>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted">Net</div>
                            <div class="fw-bold"><?php echo prMoney($net); ?></div>
                        </div>
                    </div>
                    <div class="row text-muted small text-center my-2">
                        <div class="col-6">
                            <div>Workers</div>
                            <strong><?php echo (int)$p['entry_count']; ?></strong>
                        </div>
                        <div class="col-6">
                            <div>Payroll</div>
                            <strong><?php echo prMoney($p['payroll_total']); ?></strong>
                        </div>
                        <div class="col-6">
                            <div>Budget</div>
                            <strong><?php echo prMoney($p['budget']); ?></strong>
                        </div>
                        <div class="col-6">
                            <div>Deduction</div>
                            <strong><?php echo prMoney($p['site_deduction']); ?></strong>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($is_admin): ?>
                            <a href="payroll_form.php?payroll_id=<?php echo (int)$p['id']; ?>"
                                class="btn btn-outline-primary flex-fill" title="Edit Entries">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        <?php endif; ?>
                        <a href="payroll_view.php?payroll_id=<?php echo (int)$p['id']; ?>"
                            class="btn btn-outline-secondary flex-fill" title="View / Print">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if ($is_admin): ?>
                            <form method="POST" action="payrolls.php?site_id=<?php echo (int)$site_id; ?>"
                                class="flex-fill" data-api data-confirm="Delete this payroll week?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="payroll.delete">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger w-100" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($is_admin): ?>
    <div class="modal fade" id="payrollModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="payrolls.php?site_id=<?php echo (int)$site_id; ?>" data-api>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="payroll.add">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Payroll Week</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="week_start">Week Start</label>
                                <input type="date" class="form-control" id="week_start" name="week_start" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="week_end">Week End</label>
                                <input type="date" class="form-control" id="week_end" name="week_end" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="budget">Budget / Cash Advance</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="budget" name="budget" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="site_deduction">Site Deduction</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="site_deduction"
                                name="site_deduction" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="add_expenses">Add. Expenses</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="add_expenses"
                                name="add_expenses" value="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Add Payroll</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
