<?php
// E:\PAYROLL\sites.php
// List, add, edit and delete construction sites.

$page_title = 'Sites';
$active_page = 'sites';
require_once __DIR__ . '/../src/inc/header.php';
require_once __DIR__ . '/../src/config/actions.php';

$is_admin = currentUserRole() === 'admin';

$flash = [];
$edit_site = null;

if ($is_admin && isset($_GET['edit'])) {
    $edit_site = dbGetSite((int)$_GET['edit']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = run_action((string)($_POST['action'] ?? ''), [
        'post'     => $_POST,
        'is_admin' => $is_admin,
        'user_id'  => (int)($_SESSION['user_id'] ?? 0),
        'site_id'  => 0,
    ]);
    if ($res['msg'] !== '') {
        $flash[] = [$res['type'], htmlspecialchars($res['msg'])];
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


try {
    $sites = dbGetSites();
} catch (PDOException $e) {
    $sites = [];
    $flash[] = ['danger', 'Could not load sites. Check the database connection and try again.'];
}
?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3><i class="bi bi-geo-alt"></i> Sites</h3>
        <small class="text-muted">Construction sites and their weekly payrolls.</small>
    </div>
    <?php if ($is_admin): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal">
            <i class="bi bi-plus-lg"></i> Add Site
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
    <h4><i class="bi bi-building"></i> All Sites (<?php echo count($sites); ?>)</h4>
    <?php if (!$sites): ?>
        <p class="text-muted mb-0">
            No sites yet. Add a site to get started.
        </p>
    <?php else: ?>
        <div class="table-responsive d-none d-lg-block">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Site</th>
                        <th class="text-center">Workers</th>
                        <th class="text-center">Payrolls</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $s): ?>
                        <tr>
                            <td>
                                <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>" class="fw-semibold text-decoration-none">
                                    <i class="bi bi-building"></i> <?php echo htmlspecialchars($s['name']); ?>
                                </a>
                            </td>
                            <td class="text-center">
                                <a href="site_workers.php?site_id=<?php echo (int)$s['id']; ?>">
                                    <?php echo (int)$s['worker_count']; ?>
                                </a>
                            </td>
                            <td class="text-center"><?php echo (int)$s['payroll_count']; ?></td>
                            <td class="text-end">
                                <a href="site_workers.php?site_id=<?php echo (int)$s['id']; ?>"
                                    class="btn btn-sm btn-outline-secondary" title="Workers">
                                    <i class="bi bi-people"></i>
                                </a>
                                <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>"
                                    class="btn btn-sm btn-outline-primary" title="Payrolls">
                                    <i class="bi bi-cash-stack"></i>
                                </a>
                                <?php if ($is_admin): ?>
                                    <a href="sites.php?edit=<?php echo (int)$s['id']; ?>"
                                        class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="sites.php" class="d-inline"
                                        data-api data-confirm="Delete this site and all of its payroll data?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="site.delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
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
            <?php foreach ($sites as $s): ?>
                <div class="border rounded p-3 mb-3">
                    <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>" class="fw-semibold text-decoration-none">
                        <i class="bi bi-building"></i> <?php echo htmlspecialchars($s['name']); ?>
                    </a>
                    <div class="row text-muted small text-center my-2">
                        <div class="col-6">
                            <div>Workers</div>
                            <strong><?php echo (int)$s['worker_count']; ?></strong>
                        </div>
                        <div class="col-6">
                            <div>Payrolls</div>
                            <strong><?php echo (int)$s['payroll_count']; ?></strong>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="site_workers.php?site_id=<?php echo (int)$s['id']; ?>"
                            class="btn btn-outline-secondary flex-fill" title="Workers">
                            <i class="bi bi-people"></i>
                        </a>
                        <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>"
                            class="btn btn-outline-primary flex-fill" title="Payrolls">
                            <i class="bi bi-cash-stack"></i>
                        </a>
                        <?php if ($is_admin): ?>
                            <a href="sites.php?edit=<?php echo (int)$s['id']; ?>"
                                class="btn btn-outline-secondary flex-fill" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="sites.php" class="flex-fill"
                                data-api data-confirm="Delete this site and all of its payroll data?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="site.delete">
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
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
    <div class="modal fade" id="siteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="sites.php" data-api>
                    <?php echo csrf_field(); ?>
                    <?php if ($edit_site): ?>
                        <input type="hidden" name="action" value="site.update">
                        <input type="hidden" name="id" value="<?php echo (int)$edit_site['id']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="action" value="site.add">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-<?php echo $edit_site ? 'pencil' : 'plus-lg'; ?>"></i>
                            <?php echo $edit_site ? 'Edit Site' : 'Add Site'; ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="name">Site Name</label>
                            <input type="text" class="form-control" id="name" name="name" required
                                value="<?php echo htmlspecialchars($edit_site['name'] ?? ''); ?>"
                                placeholder="e.g. ANGELES">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> <?php echo $edit_site ? 'Save Changes' : 'Add Site'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($edit_site): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                new bootstrap.Modal(document.getElementById('siteModal')).show();
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>
