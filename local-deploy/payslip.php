<?php
// E:\PAYROLL\payslip.php
// Pick a site + payroll week, then choose scope:
//   Per Person ........ one worker's compact payslip stub (print / PDF)
//   Per Site (Week) ... all workers of that week, 5 stubs per portrait page
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
$scope = ($_GET['scope'] ?? 'person') === 'site' ? 'site' : 'person';

// ---- PDF download: page HTML is NOT produced here. ----
if (!empty($_GET['download']) && $site_id > 0 && $payroll_id > 0) {
    try {
        $dl_payroll = dbGetPayroll($payroll_id);
        $dl_site = $dl_payroll ? dbGetSite((int)$dl_payroll['site_id']) : null;
        $dl_entries = $dl_payroll ? prWithCalc(dbGetPayrollEntries($payroll_id)) : [];
        if ($dl_payroll && $dl_site && (int)$dl_payroll['site_id'] === $site_id && $dl_entries) {
            if ($scope === 'site') {
                $bytes = prPdfPaySlips($dl_payroll, $dl_entries, (string)$dl_site['name']);
                $name = 'site';
            } else {
                $dl_entry = null;
                foreach ($dl_entries as $e) {
                    if ((int)$e['site_employee_id'] === $worker_id) {
                        $dl_entry = $e;
                        break;
                    }
                }
                if (!$dl_entry) {
                    $dl_entry = $dl_entries[0];
                }
                $bytes = prPdfPaySlip($dl_payroll, $dl_entry, (string)$dl_site['name']);
                $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $dl_entry['name']) ?: 'worker';
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="payslips-' . $name . '-' . $dl_payroll['week_start'] . '.pdf"');
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
        // Preselect the most recent payroll week when none chosen.
        if ($payroll_id <= 0 && $payrolls) {
            $payroll_id = (int)$payrolls[0]['id'];
        }
    }
    if ($payroll_id > 0) {
        $payroll = dbGetPayroll($payroll_id);
        if (!$payroll || (int)$payroll['site_id'] !== $site_id) {
            $payroll = null;
            $payroll_id = 0;
        } else {
            $entries = prWithCalc(dbGetPayrollEntries($payroll_id));
            foreach ($entries as $e) {
                if ((int)$e['site_employee_id'] === $worker_id) {
                    $entry = $e;
                    break;
                }
            }
            if (!$entry && $worker_id > 0) {
                $worker_id = 0;
            }
            if ($scope === 'person' && !$entry && $entries) {
                $entry = $entries[0];
                $worker_id = (int)$entry['site_employee_id'];
            }
        }
    }
} catch (PDOException $e) {
    $sites = [];
    echo '<div class="alert alert-warning">Database unavailable. Please try again later.</div>';
}

// Stubs to show (person = just theirs; site = everybody) grouped 5 per sheet.
$stub_rows = [];
if ($payroll && $entries) {
    if ($scope === 'person' && $entry) {
        $stub_rows = [$entry];
    } else {
        $stub_rows = $entries;
    }
}
$sheets = array_chunk($stub_rows, 5);
?>

<div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3><i class="bi bi-receipt-cutoff"></i> Payslip</h3>
        <small class="text-muted">Pick a site and payroll week, then print per person or the whole site's week (5 payslips per page).</small>
    </div>
</div>

<div class="content-card">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-3">
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
        <div class="col-lg-3">
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
        <div class="col-lg-2">
            <label class="form-label small mb-1" for="psScope">Print</label>
            <select class="form-select" id="psScope" name="scope">
                <option value="person" <?php echo $scope === 'person' ? 'selected' : ''; ?>>Per Person</option>
                <option value="site" <?php echo $scope === 'site' ? 'selected' : ''; ?>>Per Site (Whole Week)</option>
            </select>
        </div>
        <div class="col-lg-3">
            <label class="form-label small mb-1" for="psWorker">Worker</label>
            <select class="form-select" id="psWorker" name="worker"
                <?php echo ($scope === 'person' && $payroll_id > 0) ? '' : 'disabled'; ?>>
                <option value="">-- Pick worker --</option>
                <?php foreach ($entries as $e): ?>
                    <option value="<?php echo (int)$e['site_employee_id']; ?>" <?php
                        echo ($worker_id === (int)$e['site_employee_id']) ? 'selected' : ''; ?>>
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

<?php if ($payroll && $entries && $stub_rows): ?>
    <div class="d-flex flex-wrap gap-2 mb-3 no-print">
        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print <?php echo $scope === 'site' ? 'All' : ''; ?>
        </button>
        <a href="payslip.php?site_id=<?php echo $site_id; ?>&payroll_id=<?php echo $payroll_id; ?>&scope=<?php echo $scope; ?>&worker=<?php echo $worker_id; ?>&download=1"
            class="btn btn-primary">
            <i class="bi bi-file-earmark-pdf"></i> Download <?php echo $scope === 'site' ? 'All PDF' : 'PDF'; ?>
        </a>
        <a href="payslip.php" class="btn btn-outline-secondary">Reset</a>
    </div>

    <div class="small text-muted mb-2 no-print">
        <?php echo count($stub_rows); ?> payslip(s) &middot; prints <?php echo count($sheets); ?> page(s), 5 per page.
    </div>

    <?php foreach ($sheets as $sheet_rows): ?>
        <div class="payslip-sheet">
            <?php foreach ($sheet_rows as $se): ?>
                <div class="payslip-stub">
                    <div class="ps-head">
                        <span>PAYSLIP</span>
                        <span class="ps-site"><?php echo htmlspecialchars($site['name']); ?></span>
                        <span class="ps-week"><?php echo prDate($payroll['week_start']) . ' - ' . prDate($payroll['week_end']); ?></span>
                    </div>
                    <div class="ps-line">
                        <span><span class="ps-lbl">Worker:</span> <strong><?php echo htmlspecialchars($se['name']); ?></strong></span>
                        <span><span class="ps-lbl">Rate/Day:</span> <?php echo prMoney($se['rate']); ?></span>
                        <span><span class="ps-lbl">Days:</span> <?php echo number_format((float)$se['days_worked'], 1); ?></span>
                        <span><span class="ps-lbl">OT hrs (prev):</span> <?php echo number_format((float)$se['ot_hours'], 1); ?></span>
                    </div>
                    <div class="ps-line">
                        <span><span class="ps-lbl">Basic:</span> <?php echo prMoney($se['basic']); ?></span>
                        <span><span class="ps-lbl">OT Pay:</span> <?php echo prMoney($se['ot_pay']); ?></span>
                        <span><span class="ps-lbl">Flat:</span> <?php echo prMoney($se['flat_pay']); ?></span>
                        <span><span class="ps-lbl">Gross:</span> <strong><?php echo prMoney($se['gross']); ?></strong></span>
                    </div>
                    <div class="ps-line">
                        <span><span class="ps-lbl">Per. Cash Adv:</span> <?php echo prMoney($se['personal_cash_advance']); ?></span>
                        <span><span class="ps-lbl">Cash Adv:</span> <?php echo prMoney($se['cash_advance']); ?></span>
                        <span><span class="ps-lbl">Deduction:</span> <?php echo prMoney($se['deduction']); ?></span>
                    </div>
                    <div class="ps-net">NET PAY: <?php echo prMoney($se['net']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php elseif ($site_id > 0 && !$payrolls): ?>
    <div class="content-card">
        <p class="text-muted mb-0">This site has no payroll weeks yet. Add a payroll week first so payslips can be generated. <a href="payrolls.php?site_id=<?php echo (int)$site_id; ?>">Go to Payrolls</a></p>
    </div>
<?php elseif ($payroll && !$entries): ?>
    <div class="content-card">
        <p class="text-muted mb-0">This payroll week has no entries saved yet. Open Edit / Save Entries for the week first.</p>
    </div>
<?php elseif ($site_id > 0): ?>
    <div class="content-card">
        <p class="text-muted mb-0">Choose a payroll week above (or <a href="payrolls.php?site_id=<?php echo (int)$site_id; ?>">add one</a>) to generate payslips.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc/footer.php'; ?>