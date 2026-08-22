<?php
// E:\PAYROLL\ca_history.php
// Cash Advance History page: Personal CA ledger + Repaid Per Week + weekly Cash Advances.

require_once __DIR__ . '/../src/config/session.php';
payroll_session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/../src/config/DBpayroll.php';
require_once __DIR__ . '/../src/config/actions.php';

$page_title = 'CA History';
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

require_once __DIR__ . '/../src/inc/header.php';
?>

<div class="page-head">
    <h3><i class="bi bi-clock-history"></i> Cash Advance History</h3>
    <small class="text-muted">Personal CA ledger, repayments, and weekly cash advances.</small>
</div>

<?php foreach ($flash as $f): ?>
    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
        <?php echo $f[1]; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="content-card">
    <h4><i class="bi bi-cash-coin"></i> Personal Cash Advance History</h4>

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
                                <?php if ($is_admin): ?><th></th><?php endif; ?>
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

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>
