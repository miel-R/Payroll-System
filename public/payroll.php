<?php
// E:\PAYROLL\payroll.php
// Payroll hub: one card per site (add payroll week / edit entries / view),
// plus the cash advance and personal cash advance history lists.

$page_title = 'Payroll';
$active_page = 'payroll';
require_once __DIR__ . '/../src/inc/header.php';
require_once __DIR__ . '/../src/config/actions.php';

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
    $sites = dbSitesWithLatestPayroll();
    $ca_history = dbCashAdvanceHistory();
    $pca_ledger = dbPersonalCaHistoryAll();
    $pca_recovery = dbPersonalCaRecoveryHistory();
} catch (PDOException $e) {
    $sites = [];
    $ca_history = [];
    $pca_ledger = [];
    $pca_recovery = [];
    $flash[] = ['danger', 'Could not load payroll data. Check the database connection and try again.'];
}

$today = date('Y-m-d');
$week_start_default = date('Y-m-d', strtotime('last sunday', strtotime($today . ' +1 day')));
$week_end_default = date('Y-m-d', strtotime($week_start_default . ' +6 days'));

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

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3><i class="bi bi-cash-stack"></i> Payroll</h3>
        <small class="text-muted">Weekly payroll hub: add a week, enter entries, and track cash advances.</small>
    </div>
</div>

<?php foreach ($flash as $f): ?>
    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
        <?php echo $f[1]; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="content-card">
    <h4><i class="bi bi-building"></i> Sites</h4>
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
                        <?php if ($s['latest_payroll_id']): ?>
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
                        <div class="d-flex gap-2 mt-auto card-actions">
                            <?php if ($is_admin): ?>
                                <button type="button" class="btn btn-sm btn-primary flex-fill" data-bs-toggle="modal"
                                    data-bs-target="#addPayrollModal" data-site="<?php echo (int)$s['id']; ?>"
                                    data-site-name="<?php echo htmlspecialchars($s['name']); ?>">
                                    <i class="bi bi-plus-circle"></i> Add Payroll Week
                                </button>
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
    <?php endif; ?>
</div>

<div class="content-card">
    <h4><i class="bi bi-building"></i> Sites</h4>
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
                        <?php if ($s['latest_payroll_id']): ?>
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
                        <div class="d-flex gap-2 mt-auto card-actions">
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
    <?php endif; ?>
</div>

<?php if ($is_admin): ?>
    <div class="modal fade" id="addPayrollModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="payroll.php" data-api>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="payroll.add">
                    <input type="hidden" name="site_id" id="addPayrollSiteId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Payroll Week - <span id="addPayrollSiteName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info py-2 small">
                            The week's DTR attendance and the previous week's saved entries are checked first.
                        </div>
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

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>