<?php
// E:\PAYROLL\payrolls.php
// Weekly payroll periods for a site. Add, list and delete a week.

$page_title = 'Payrolls';
$active_page = 'sites';
require_once __DIR__ . '/inc/header.php';

$is_admin = currentUserRole() === 'admin';

$site_id = (int)($_GET['site_id'] ?? 0);

try {
    $site = dbGetSite($site_id);
} catch (PDOException $e) {
    $site = null;
}

if (!$site) {
    header('Location: sites.php');
    exit();
}

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$is_admin) {
        $flash[] = ['warning', 'Finance users can only view payrolls. Changes not saved.'];
    } else {
        try {
            if ($action === 'add') {
                $week_start = trim($_POST['week_start'] ?? '');
                $week_end = trim($_POST['week_end'] ?? '');
                $budget = (float)($_POST['budget'] ?? 0);
                $site_deduction = (float)($_POST['site_deduction'] ?? 0);
                $add_expenses = (float)($_POST['add_expenses'] ?? 0);

                if ($week_start === '' || $week_end === '') {
                    $flash[] = ['danger', 'Week start and end dates are required.'];
                } elseif (strtotime($week_end) < strtotime($week_start)) {
                    $flash[] = ['danger', 'Week end must be on or after week start.'];
                } elseif (dbGetPayrollByWeek($site_id, $week_start, $week_end)) {
                    $flash[] = ['warning', 'A payroll already exists for this week.'];
                } else {
                    dbAddPayroll($site_id, $week_start, $week_end, $budget, $site_deduction, $add_expenses);
                    $flash[] = ['success', 'Payroll week added. Now add the per-worker entries.'];
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    dbDeletePayroll($id);
                    $flash[] = ['success', 'Payroll week deleted.'];
                }
            }
        } catch (PDOException $e) {
            $flash[] = ['danger', 'Could not save: ' . htmlspecialchars($e->getMessage())];
        }
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
                                        class="d-inline" data-ajax data-confirm="Delete this payroll week?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
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
                                class="flex-fill" data-ajax data-confirm="Delete this payroll week?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
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
                <form method="POST" action="payrolls.php?site_id=<?php echo (int)$site_id; ?>" data-ajax>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
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
