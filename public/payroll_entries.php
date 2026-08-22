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

try {
    $sites = dbSitesWithLatestPayroll();
} catch (PDOException $e) {
    $sites = [];
    $flash[] = ['danger', 'Could not load sites. Check the database connection and try again.'];
}

$site_payrolls = [];
foreach ($sites as $s) {
    try {
        $weeks = dbGetPayrolls((int)$s['id']);
        usort($weeks, function ($a, $b) {
            return strtotime($b['week_start']) <=> strtotime($a['week_start']);
        });
        $site_payrolls[(int)$s['id']] = array_slice($weeks, 0, 5);
    } catch (PDOException $e) {
        $site_payrolls[(int)$s['id']] = [];
    }
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
    <?php if (!$sites): ?>
        <p class="text-muted mb-0">
            No sites yet. <a href="sites.php">Add a site</a> to get started.
        </p>
    <?php else: ?>
        <div class="row">
            <?php foreach ($sites as $s): ?>
                <?php $weeks = $site_payrolls[(int)$s['id']]; ?>
                <div class="col-md-6 col-xl-4">
                    <div class="border rounded p-3 mb-3 d-flex flex-column h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>" class="fw-semibold text-decoration-none">
                                    <i class="bi bi-building"></i> <?php echo htmlspecialchars($s['name']); ?>
                                </a>
                                <div class="text-muted small"><?php echo (int)$s['worker_count']; ?> workers</div>
                            </div>
                            <span class="badge text-bg-light">last 5 of <?php echo (int)$s['payroll_count']; ?></span>
                        </div>

                        <?php if (!$weeks): ?>
                            <div class="text-muted small my-2">
                                No payroll weeks yet.
                                <?php if ($is_admin): ?>
                                    <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>">Add one</a>.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush my-2">
                                <?php foreach ($weeks as $w): ?>
                                    <a href="<?php echo $is_admin ? 'payroll_form.php' : 'payroll_view.php'; ?>?payroll_id=<?php echo (int)$w['id']; ?>"
                                        class="list-group-item list-group-item-action px-2 py-2">
                                        <div class="d-flex justify-content-between align-items-center small">
                                            <span class="fw-semibold">
                                                <?php echo prDate($w['week_start']) . ' - ' . prDate($w['week_end']); ?>
                                            </span>
                                            <span class="text-muted"><?php echo (int)$w['entry_count']; ?>/<?php echo (int)$s['worker_count']; ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>"
                            class="small text-decoration-none mt-auto">View all weeks &raquo;</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>
