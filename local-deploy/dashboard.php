<?php
require_once __DIR__ . '/config/session.php';
payroll_session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$page_title = 'Dashboard';
$active_page = 'dashboard';
require_once __DIR__ . '/inc/header.php';

$user = dbGetUserById($_SESSION['user_id']);

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

$tables_ok = false;
try {
    $tables_ok = dbTableExists('sites');
} catch (Exception $e) {
    $tables_ok = false;
}

if ($tables_ok) {
    $stats = dbPayrollGrandTotals();
    $sites = dbGetSites();
} else {
    $stats = ['site_count' => 0, 'worker_count' => 0, 'payroll_count' => 0, 'total_payroll' => 0];
    $sites = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">Welcome, <?php echo htmlspecialchars($user['username']); ?>!</h3>
        <small class="text-muted"><?php echo date('F d, Y'); ?> - Payroll overview</small>
    </div>
    <a href="sites.php" class="btn btn-primary"><i class="bi bi-geo-alt"></i> Manage Sites</a>
</div>

<div class="row">
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#667eea,#764ba2);">
            <div class="stat-icon"><i class="bi bi-geo-alt"></i></div>
            <div class="stat-value"><?php echo $stats['site_count']; ?></div>
            <div class="stat-label">Sites</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#11998e,#38ef7d);">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div class="stat-value"><?php echo $stats['worker_count']; ?></div>
            <div class="stat-label">Site Assignments</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#f7971e,#ffd200);">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-value"><?php echo $stats['payroll_count']; ?></div>
            <div class="stat-label">Payroll Weeks</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#f093fb,#f5576c);">
            <div class="stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div class="stat-value"><?php echo '&#8369;' . prMoney($stats['total_payroll']); ?></div>
            <div class="stat-label">Total Payroll</div>
        </div>
    </div>
</div>

<div class="content-card">
    <h4><i class="bi bi-building"></i> Sites</h4>
    <?php if (!$sites): ?>
        <p class="text-muted mb-0">
            No sites yet. <a href="sites.php">Add a site</a> to get started.
        </p>
    <?php else: ?>
        <div class="table-responsive">
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
                    <?php foreach (array_slice($sites, 0, 5) as $s): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars($s['name']); ?></td>
                            <td class="text-center"><?php echo (int)$s['worker_count']; ?></td>
                            <td class="text-center"><?php echo (int)$s['payroll_count']; ?></td>
                            <td class="text-end">
                                <a href="payrolls.php?site_id=<?php echo (int)$s['id']; ?>"
                                    class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-cash-stack"></i> Payrolls
                                </a>
                                <a href="site_workers.php?site_id=<?php echo (int)$s['id']; ?>"
                                    class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-people"></i> Workers
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($sites) > 5): ?>
            <a href="sites.php">View all sites <i class="bi bi-arrow-right"></i></a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
