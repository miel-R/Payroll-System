<?php
// E:\PAYROLL\payslip.php
// Pick a site, payroll week and worker and print / download a worker's payslip.
// Read-only for all roles (admin + finance).

require_once __DIR__ . '/config/session.php';
if (session_status() === PHP_SESSION_NONE) {
    payroll_session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/config/DBpayroll.php';
require_once __DIR__ . '/config/PDF.php';

$site_id = (int)($_GET['site_id'] ?? 0);
$payroll_id = (int)($_GET['payroll_id'] ?? 0);
$worker_id = (int)($_GET['worker'] ?? 0);

// ---- PDF download: returns the payslip as a file, no page HTML. ----
$dl_site = null;
if (!empty($_GET['download']) && $site_id > 0 && $payroll_id > 0 && $worker_id > 0) {
    try {
        $dl_payroll = dbGetPayroll($payroll_id);
        $dl_site = $dl_payroll ? dbGetSite((int)$dl_payroll['site_id']) : null;
        $dl_entries = $dl_payroll ? prWithCalc(dbGetPayrollEntries($payroll_id)) : [];
        $dl_entry = null;
        foreach ($dl_entries as $e) {
            if ((int)$e['site_employee_id'] === $worker_id) {
                $dl_entry = $e;
                break;
            }
        }
        if ($dl_payroll && $dl_site && $dl_entry) {
            $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $dl_entry['name']) ?: 'worker';
            $bytes = prPdfPaySlip($dl_payroll, $dl_entry, (string)$dl_site['name']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="payslip-' . $name . '-' . $dl_payroll['week_start'] . '.pdf"');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
            exit();
        }
    } catch (PDOException $e) {
        // fall through to the page (warning shows)
    }
}

$page_title = 'Payslip';
$active_page = 'payslip';
require_once __DIR__ . '/inc/header.php';

$sites = [];
$site = null;
$payrolls = [];
$payroll = null;
$entries = [];
$entry = null;
$totals = null;

try {
    $sites = dbGetSites();
    if ($site_id > 0) {
        $site = dbGetSite($site_id);
        if (!$site) {
            $site_id = 0;
        }
    }
    if ($site_id > 0) {
        $payrolls = dbGetPayrolls($site_id);
    }
    if ($payroll_id > 0) {
        $payroll = dbGetPayroll($payroll_id);
        if (!$payroll || (int)$payroll['site_id'] !== $site_id) {
            $payroll = null;
            $payroll_id = 0;
        }
    }
    if ($payroll_id > 0) {
        $entries = prWithCalc(dbGetPayrollEntries($payroll_id));
        foreach ($entries as $e) {
            if ((int)$e['site_employee_id'] === $worker_id) {
                $entry = $e;
                break;
            }
        }
        if (!$entry) {
            $worker_id = 0;
        }
        $totals = prPayrollTotals($entries, $payroll);
    }
} catch (PDOException $e) {
    $sites = [];
    echo '<div class="alert alert-warning">Database unavailable. Please try again later.</div>';
}
?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3><i class="bi bi-receipt-cutoff"></i> Payslip</h3>
        <small class="text-muted">Pick a site, payroll week and worker to view, print or download a payslip.</small>
    </div>
</div>

<div class="content-card">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-4">
            <label class="form-label small mb-1" for="psSite">Site</label>
            <select class="form-select" id="psSite" name="site_id">
                <option value="">-- Select site --</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo $site_id === (int)$s['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-4">
            <label class="form-label small mb-1" for="psPayroll">Payroll Week</label>
            <select class="form-select" id="psPayroll" name="payroll_id" <?php echo $site_id > 0 ? '' : 'disabled'; ?>>
                <option value="">-- Select week --</option>
                <?php foreach ($payrolls as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo $payroll_id === (int)$p['id'] ? 'selected' : ''; ?>>
                        <?php echo prDate($p['week_start']) . ' - ' . prDate($p['week_end']) . ' (' . (int)$p['entry_count'] . ' entries)'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3">
            <label class="form-label small mb-1" for="psWorker">Worker</label>
            <select class="form-select" id="psWorker" name="worker" <?php echo $payroll_id > 0 ? '' : 'disabled'; ?>>
                <option value="">-- Select worker --</option>
                <?php foreach ($entries as $e): ?>
                    <option value="<?php echo (int)$e['site_employee_id']; ?>" <?php echo $worker_id === (int)$e['site_employee_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($e['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 d-grid">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<?php if ($entry && $payroll && $site): ?>
    <?php
        $att = str_split(prNormAtt($entry['attendance'] ?? ''));
        $otd = prOtDailyArray($entry['ot_daily'] ?? '');
    ?>
    <div class="d-flex flex-wrap gap-2 mb-3 no-print">
        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="payslip.php?site_id=<?php echo $site_id; ?>&payroll_id=<?php echo $payroll_id; ?>&worker=<?php echo $worker_id; ?>&download=1"
            class="btn btn-primary">
            <i class="bi bi-file-earmark-pdf"></i> Download PDF
        </a>
        <a href="payslip.php" class="btn btn-outline-secondary">Reset</a>
    </div>

    <div class="content-card" id="payslip">
        <div class="text-center mb-3">
            <div class="fw-bold fs-5">PAYSLIP</div>
            <div class="small text-muted"><?php echo htmlspecialchars($site['name']); ?> &middot;
                Week: <?php echo prDate($payroll['week_start']) . ' - ' . prDate($payroll['week_end']); ?></div>
        </div>

        <table class="table table-bordered table-sm align-middle mb-3">
            <tbody>
                <tr>
                    <th class="table-light" style="width:25%">Worker</th>
                    <td class="fw-semibold"><?php echo htmlspecialchars($entry['name']); ?></td>
                    <th class="table-light" style="width:15%">Position</th>
                    <td><?php echo htmlspecialchars($entry['position'] ?: '-'); ?></td>
                </tr>
                <tr>
                    <th class="table-light">Rate / Day</th>
                    <td class="text-end"><?php echo prMoney($entry['rate']); ?></td>
                    <th class="table-light">Days Worked</th>
                    <td class="text-end"><?php echo $entry['days_worked']; ?></td>
                </tr>
            </tbody>
        </table>

        <div class="row g-3">
            <div class="col-6">
                <h6 class="text-muted"><i class="bi bi-cash-coin"></i> Earnings</h6>
                <table class="table table-bordered table-sm align-middle mb-0">
                    <tbody>
                        <tr><th class="table-light">Basic Pay</th><td class="text-end"><?php echo prMoney($entry['basic']); ?></td></tr>
                        <tr><th class="table-light">OT Hours (prev week)</th><td class="text-end"><?php echo $entry['ot_hours']; ?> hrs</td></tr>
                        <tr><th class="table-light">OT Rate</th><td class="text-end"><?php echo prMoney($entry['ot_rate']); ?></td></tr>
                        <tr><th class="table-light">OT Pay</th><td class="text-end"><?php echo prMoney($entry['ot_pay']); ?></td></tr>
                        <tr><th class="table-light">Flat Pay</th><td class="text-end"><?php echo prMoney($entry['flat_pay']); ?></td></tr>
                        <tr class="table-light"><th>GROSS</th><td class="text-end fw-semibold"><?php echo prMoney($entry['gross']); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-6">
                <h6 class="text-muted"><i class="bi bi-wallet2"></i> Deductions</h6>
                <table class="table table-bordered table-sm align-middle mb-0">
                    <tbody>
                        <tr><th class="table-light">Per. Cash Adv.</th><td class="text-end"><?php echo prMoney($entry['personal_cash_advance']); ?></td></tr>
                        <tr><th class="table-light">Cash Adv.</th><td class="text-end"><?php echo prMoney($entry['cash_advance']); ?></td></tr>
                        <tr><th class="table-light">Deduction</th><td class="text-end"><?php echo prMoney($entry['deduction']); ?></td></tr>
                        <tr class="table-dark"><th>NET PAY</th><td class="text-end fw-bold fs-5"><?php echo prMoney($entry['net']); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-7">
                <h6 class="text-muted">Attendance (Sun &middot; Mon &middot; Tue &middot; Wed &middot; Thu &middot; Fri &middot; Sat)</h6>
                <div class="d-flex gap-1 mb-2">
                    <?php foreach ($att as $code): ?>
                        <span class="badge text-bg-light border" style="min-width:2em"><?php echo htmlspecialchars($code); ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="small text-muted">OT per day: <?php echo htmlspecialchars(implode(' / ', $otd)); ?> hrs</div>
            </div>
            <div class="col-5">
                <table class="table table-bordered table-sm align-middle mb-0 small">
                    <tbody>
                        <tr><th class="table-light">Site Payroll</th><td class="text-end"><?php echo prMoney($totals['payroll_total']); ?></td></tr>
                        <tr><th class="table-light">Cash Adv. (budget)</th><td class="text-end"><?php echo prMoney($totals['budget']); ?></td></tr>
                        <tr><th class="table-light">Site Total</th><td class="text-end fw-semibold"><?php echo prMoney($totals['net']); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row mt-5 pt-3">
            <div class="col-6">Prepared by: <u>&nbsp;</u></div>
            <div class="col-6 text-end">Received by: <u>&nbsp;</u></div>
        </div>
    </div>
<?php elseif ($site_id > 0 || $payroll_id > 0 || $worker_id > 0): ?>
    <div class="content-card">
        <p class="text-muted mb-0">Select a site, payroll week and worker above to view its payslip.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc/footer.php'; ?>