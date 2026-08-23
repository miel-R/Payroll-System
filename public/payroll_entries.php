<?php
// E:\PAYROLL\payroll_entries.php
// Entry: every site with its latest 5 payroll weeks only, so nothing loads heavy.

require_once __DIR__ . '/../src/config/session.php';
payroll_session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/../src/config/DBpayroll.php';

$page_title = 'Payroll Entry';
$active_page = 'payroll';

$is_admin = currentUserRole() === 'admin';

$flash = [];

// One round-trip for every site's newest 5 weeks (instead of a query per site).
$grouped = [];
try {
    $grouped = dbRecentPayrollsPerSite(5);
} catch (PDOException $e) {
    $flash[] = ['danger', 'Could not load payroll weeks. Check the database connection and try again.'];
}

require_once __DIR__ . '/../src/inc/header.php';
?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3><i class="bi bi-pencil-square"></i> Payroll Entry</h3>
        <small class="text-muted">Latest 5 entry weeks per site. Older weeks live under "View all weeks".</small>
    </div>
</div>

<?php foreach ($flash as $f): ?>
    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
        <?php echo $f[1]; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="content-card">
    <?php if (!$grouped): ?>
        <p class="text-muted mb-0">
            No payroll weeks yet. <a href="payrolls.php">Add one</a> to get started.
        </p>
    <?php else: ?>
        <div class="row">
            <?php foreach ($grouped as $siteId => $g): ?>
                <?php $weeks = $g['weeks']; ?>
                <div class="col-md-6 col-xl-4">
                    <div class="border rounded p-3 mb-3 d-flex flex-column h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <a href="payrolls.php?site_id=<?php echo $siteId; ?>" class="fw-semibold text-decoration-none">
                                    <i class="bi bi-building"></i> <?php echo htmlspecialchars($g['site_name']); ?>
                                </a>
                                <div class="text-muted small"><?php echo (int)$g['worker_count']; ?> workers</div>
                            </div>
                            <span class="badge text-bg-light">last <?php echo count($weeks); ?></span>
                        </div>

                        <?php if (!$weeks): ?>
                            <div class="text-muted small my-2">No weeks yet.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush my-2">
                                <?php foreach ($weeks as $w): ?>
                                    <a href="<?php echo $is_admin ? 'payroll_form.php' : 'payroll_view.php'; ?>?payroll_id=<?php echo (int)$w['id']; ?>"
                                        class="list-group-item list-group-item-action px-2 py-2">
                                        <div class="d-flex justify-content-between align-items-center small">
                                            <span class="fw-semibold">
                                                <?php echo prDate($w['week_start']) . ' - ' . prDate($w['week_end']); ?>
                                            </span>
                                            <span class="text-muted"><?php echo (int)$w['entry_count']; ?>/<?php echo (int)$g['worker_count']; ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <a href="payrolls.php?site_id=<?php echo $siteId; ?>"
                            class="small text-decoration-none mt-auto">View all weeks &raquo;</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>
