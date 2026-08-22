<?php
// E:\PAYROLL\site_workers.php
// Manage the workers assigned to a site (position + daily rate).

$page_title = 'Site Workers';
$active_page = 'sites';
require_once __DIR__ . '/../src/inc/header.php';
require_once __DIR__ . '/../src/config/actions.php';

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
$edit_worker = null;

if ($is_admin && isset($_GET['edit'])) {
    $edit_worker = dbGetSiteEmployee((int)$_GET['edit']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = run_action((string)($_POST['action'] ?? ''), [
        'post'     => $_POST,
        'is_admin' => $is_admin,
        'user_id'  => (int)($_SESSION['user_id'] ?? 0),
        'site_id'  => $site_id,
    ]);
    if ($res['msg'] !== '') {
        $flash[] = [$res['type'], htmlspecialchars($res['msg'])];
    }
}

$workers = dbGetSiteEmployees($site_id);
?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <a href="sites.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left"></i> All Sites</a>
        <h3><i class="bi bi-people"></i> <?php echo htmlspecialchars($site['name']); ?></h3>
        <small class="text-muted">Manage workers and their daily rates for this site.</small>
    </div>
    <div class="d-flex gap-2">
        <?php if ($is_admin): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#workerModal">
                <i class="bi bi-person-plus"></i> Add Worker
            </button>
        <?php endif; ?>
        <a href="payrolls.php?site_id=<?php echo (int)$site_id; ?>" class="btn btn-outline-primary">
            <i class="bi bi-cash-stack"></i> Payrolls
        </a>
    </div>
</div>

<?php foreach ($flash as $f): ?>
    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
        <?php echo $f[1]; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="content-card">
    <h4><i class="bi bi-list-ul"></i> Workers (<?php echo count($workers); ?>)</h4>
    <?php if (!$workers): ?>
        <p class="text-muted mb-0">
            No workers assigned yet.
            <?php if ($is_admin): ?>Add one using the button above.<?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-responsive d-none d-lg-block">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Position</th>
                        <th class="text-end">Daily Rate</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workers as $w): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars($w['name']); ?></td>
                            <td><?php echo htmlspecialchars($w['position'] ?: '-'); ?></td>
                            <td class="text-end"><?php echo prMoney($w['rate']); ?></td>
                            <td class="text-end">
                                <?php if ($is_admin): ?>
                                    <a href="site_workers.php?site_id=<?php echo (int)$site_id; ?>&edit=<?php echo (int)$w['id']; ?>"
                                        class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="site_workers.php?site_id=<?php echo (int)$site_id; ?>"
                                        class="d-inline"
                                        data-api data-confirm="Remove this worker and all of their payroll entries?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="worker.delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
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
            <?php foreach ($workers as $w): ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-semibold"><?php echo htmlspecialchars($w['name']); ?></div>
                        <div class="text-end">
                            <div class="small text-muted">Daily Rate</div>
                            <strong><?php echo prMoney($w['rate']); ?></strong>
                        </div>
                    </div>
                    <div class="text-muted small"><?php echo htmlspecialchars($w['position'] ?: '-'); ?></div>
                    <?php if ($is_admin): ?>
                        <div class="d-flex gap-2 mt-2">
                            <a href="site_workers.php?site_id=<?php echo (int)$site_id; ?>&edit=<?php echo (int)$w['id']; ?>"
                                class="btn btn-outline-secondary flex-fill" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="site_workers.php?site_id=<?php echo (int)$site_id; ?>"
                                class="flex-fill"
                                data-api data-confirm="Remove this worker and all of their payroll entries?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="worker.delete">
                                <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger w-100" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($is_admin): ?>
    <div class="modal fade" id="workerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="site_workers.php?site_id=<?php echo (int)$site_id; ?>" data-api>
                    <?php echo csrf_field(); ?>
                    <?php if ($edit_worker): ?>
                        <input type="hidden" name="action" value="worker.update">
                        <input type="hidden" name="id" value="<?php echo (int)$edit_worker['id']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="action" value="worker.add">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-<?php echo $edit_worker ? 'pencil' : 'person-plus'; ?>"></i>
                            <?php echo $edit_worker ? 'Edit Worker' : 'Add Worker'; ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="name">Employee Name</label>
                            <input type="text" class="form-control" id="name" name="name"
                                value="<?php echo htmlspecialchars($edit_worker['name'] ?? ''); ?>"
                                <?php echo $edit_worker ? 'readonly' : 'required'; ?>
                                placeholder="e.g. RODERICK">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="position">Position</label>
                            <input type="text" class="form-control" id="position" name="position"
                                value="<?php echo htmlspecialchars($edit_worker['position'] ?? ''); ?>"
                                placeholder="e.g. FOREMAN">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="rate">Daily Rate</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="rate" name="rate"
                                value="<?php echo htmlspecialchars($edit_worker['rate'] ?? ''); ?>" placeholder="0.00">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> <?php echo $edit_worker ? 'Save Changes' : 'Add Worker'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($edit_worker): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                new bootstrap.Modal(document.getElementById('workerModal')).show();
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>
