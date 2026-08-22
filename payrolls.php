<?php
// E:\PAYROLL\payrolls.php
// Add Payroll hub: no site_id -> pick-card list of every site.
// With ?site_id=X -> that site's weekly payroll periods (add / edit / delete).

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
$flash = [];

if ($site_id > 0) {
    try {
        $site = dbGetSite($site_id);
    } catch (PDOException $e) {
        $site = null;
    }
    if (!$site) {
        header('Location: payrolls.php');
        exit();
    }
    try {
        $payrolls = dbGetPayrolls($site_id);
    } catch (PDOException $e) {
        $payrolls = [];
        $flash[] = ['danger', 'Could not load payroll weeks. Check the database connection and try again.'];
    }
} else {
    try {
        $sites = dbSitesWithLatestPayroll();
    } catch (PDOException $e) {
        $sites = [];
        $flash[] = ['danger', 'Could not load sites. Check the database connection and try again.'];
    }
}

require_once __DIR__ . '/inc/header.php';

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
}
?>

<?php if ($site_id === 0): ?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3><i class="bi bi-plus-circle"></i> Add Payroll</h3>
        <small class="text-muted">Pick a site, then add its weekly payroll ("BALI BINYE" budget included).</small>
    </div>
</div>

<?php foreach ($flash as $f): ?>
    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
        <?php echo $f[1]; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="content-card">
    <?php if (!$sites): ?>
        <p class="text-muted mb-0">
            No sites yet. <a href="sites.php">Add a site</a> to get started.
        </p>
    <?php else: ?>
        <div class="row">
            <?php foreach ($sites as $s): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="border rounded p-3 mb-3 d-flex flex-column h-100">
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
                            <span class="badge text-bg-light"><?php echo (int)$s['latest_entries']; ?>/<?php echo (int)$s['worker_count']; ?> entries</span>
                        </div>
                        <?php if (!empty($s['latest_payroll_id'])): ?>
                            <div class="row text-muted small text-center my-2">
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
                            </div>
                        <?php else: ?>
                            <div class="text-muted small my-2">No payroll weeks yet.</div>
                        <?php endif; ?>
                        <div class="d-flex gap-2 mt-auto flex-wrap">
                            <?php if ($is_admin): ?>
                                <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>"
                                    class="btn btn-sm btn-primary flex-fill">
                                    <i class="bi bi-calendar-week"></i> Manage Weeks
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="modal"
                                    data-bs-target="#addPayrollModal" data-site="<?php echo (int)$s['id']; ?>"
                                    data-site-name="<?php echo htmlspecialchars($s['name']); ?>">
                                    <i class="bi bi-plus-circle"></i> Add Week
                                </button>
                            <?php else: ?>
                                <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>"
                                    class="btn btn-sm btn-outline-secondary flex-fill">
                                    <i class="bi bi-calendar-week"></i> View Weeks
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($is_admin && !empty($sites)): ?>
    <?php
    $today = date('Y-m-d');
    $week_start_default = date('Y-m-d', strtotime('last sunday', strtotime($today . ' +1 day')));
    $week_end_default = date('Y-m-d', strtotime($week_start_default . ' +6 days'));
    ?>
    <div class="modal fade" id="addPayrollModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="payrolls.php" data-api>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="payroll.add">
                    <input type="hidden" name="site_id" id="addPayrollSiteId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Payroll Week - <span id="addPayrollSiteName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label" for="addWeekStart">Week Start</label>
                                <input type="date" class="form-control" id="addWeekStart" name="week_start"
                                    value="<?php echo $week_start_default; ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="addWeekEnd">Week End</label>
                                <input type="date" class="form-control" id="addWeekEnd" name="week_end"
                                    value="<?php echo $week_end_default; ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="addBudget">Budget / Cash Advance (BALI BINYE)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="addBudget" name="budget" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="addDeduction">Site Deduction</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="addDeduction" name="site_deduction" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="addExpenses">Add. Expenses</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="addExpenses" name="add_expenses" value="0">
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-bs-target="#addPayrollModal"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.getElementById('addPayrollSiteId').value = btn.getAttribute('data-site') || '';
                    document.getElementById('addPayrollSiteName').textContent = btn.getAttribute('data-site-name') || '';
                });
            });
        });
    </script>
<?php endif; ?>

<?php else: ?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <a href="payrolls.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left"></i> Sites</a>
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

<?php endif; ?>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
