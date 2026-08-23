<?php
// E:\PAYROLL\payroll_view.php
// Printable weekly payroll report. Three modes:
//   no params        -> pick a site
//   ?site_id=X       -> pick one of that site's weeks
//   ?payroll_id=X    -> the report

require_once __DIR__ . '/../src/config/session.php';
payroll_session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
require_once __DIR__ . '/../src/config/DBpayroll.php';

$page_title = 'Payroll Report';
$active_page = 'payroll';

$payroll_id = (int)($_GET['payroll_id'] ?? 0);
$site_id = (int)($_GET['site_id'] ?? 0);

$sites = [];
$site = null;
$payroll = null;
$weeks = [];
$entries = [];
$totals = [
    'payroll_total' => 0,
    'budget'        => 0,
    'site_deduction'=> 0,
    'add_expenses'  => 0,
    'net'           => 0,
];

try {
    if ($payroll_id > 0) {
        $payroll = dbGetPayroll($payroll_id);
        if (!$payroll) {
            header('Location: payroll_view.php');
            exit();
        }
        $site = dbGetSite((int)$payroll['site_id']);
        if (!$site) {
            header('Location: payroll_view.php');
            exit();
        }
        $entries = prWithCalc(dbGetPayrollEntries($payroll_id));
        $totals = prPayrollTotals($entries, $payroll);
    } elseif ($site_id > 0) {
        $site = dbGetSite($site_id);
        if (!$site) {
            header('Location: payroll_view.php');
            exit();
        }
        $weeks = dbGetPayrolls($site_id);
        usort($weeks, function ($a, $b) {
            return strtotime($b['week_start']) <=> strtotime($a['week_start']);
        });
    } else {
        $sites = dbSitesWithLatestPayroll();
    }
} catch (PDOException $e) {
    $flash = ['danger', 'Could not load payroll data. Check the database connection and try again.'];
}

$mode = $payroll ? 'report' : ($site ? 'picker-week' : 'picker-site');

require_once __DIR__ . '/../src/inc/header.php';
?>

<?php if (isset($flash)): ?>
    <?php foreach ((array)$flash as $f): ?>
        <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars(is_array($f[1]) ? '' : $f[1]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($mode === 'picker-site'): ?>

<div class="page-head">
    <h3><i class="bi bi-eye"></i> View Payroll Report</h3>
    <small class="text-muted">Pick a site, then a week.</small>
</div>

<div class="content-card">
    <?php if (!$sites): ?>
        <p class="text-muted mb-0">No sites yet.</p>
    <?php else: ?>
        <div class="row">
            <?php foreach ($sites as $s): ?>
                <div class="col-md-6 col-xl-4">
                    <a href="payroll_view.php?site_id=<?php echo (int)$s['id']; ?>"
                        class="border rounded p-3 mb-3 d-flex justify-content-between align-items-center text-decoration-none text-reset"
                        style="display:flex;">
                        <span>
                            <span class="fw-semibold"><i class="bi bi-building"></i> <?php echo htmlspecialchars($s['name']); ?></span>
                            <span class="d-block text-muted small"><?php echo (int)$s['payroll_count']; ?> payroll weeks</span>
                        </span>
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($mode === 'picker-week'): ?>

<div class="page-head">
    <a href="payroll_view.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left"></i> Sites</a>
    <h3><i class="bi bi-eye"></i> <?php echo htmlspecialchars($site['name']); ?></h3>
    <small class="text-muted">Pick the week to open its report.</small>
</div>

<div class="content-card">
    <?php if (!$weeks): ?>
        <p class="text-muted mb-0">No payroll weeks for this site yet.</p>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($weeks as $w): ?>
                <a href="payroll_view.php?payroll_id=<?php echo (int)$w['id']; ?>"
                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><?php echo prDate($w['week_start']) . ' - ' . prDate($w['week_end']); ?></span>
                    <span class="small text-muted"><?php echo (int)$w['entry_count']; ?> workers &middot; <?php echo prMoney($w['payroll_total']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="no-print d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <?php if (currentUserRole() === 'admin'): ?>
        <a href="payroll_form.php?payroll_id=<?php echo (int)$payroll['id']; ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-pencil-square"></i> Edit Entries
        </a>
    <?php else: ?>
        <a href="payroll_view.php?site_id=<?php echo (int)$payroll['site_id']; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Weeks
        </a>
    <?php endif; ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
        <i class="bi bi-printer"></i> Print / Save PDF
    </button>
</div>

<?php
$day_labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
$prev_start = date('Y-m-d', strtotime($payroll['week_start'] . ' -7 days'));
$prev_end = date('Y-m-d', strtotime($payroll['week_end'] . ' -7 days'));
?>

<div class="content-card">
    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
        <div>
            <h4 class="mb-0"><?php echo htmlspecialchars($site['name']); ?></h4>
            <div class="text-muted small">WEEKLY PAYROLL</div>
        </div>
        <div class="text-end">
            <div class="fw-semibold">
                ATTENDANCE: <?php echo date('F j', strtotime($payroll['week_start'])); ?> -
                <?php echo date('F j, Y', strtotime($payroll['week_end'])); ?>
            </div>
            <div class="text-muted small">BALI BINYE NA ENGR: <strong><?php echo prMoney($totals['budget']); ?></strong></div>
            <div class="text-muted small" style="font-size:0.75em">* OT hrs = the PREVIOUS week's DTR OT (Sun-Sat), paid on this payroll. This week's OT (incl. SATURDAY) is paid on the NEXT payroll.</div>
        </div>
    </div>

    <div class="alert alert-info py-2 small mb-3" role="alert">
        <i class="bi bi-info-circle"></i> <strong>OT HRS*</strong> came from the <strong>PREVIOUS week's</strong> DTR
        (<strong>Sun <?php echo prDate($prev_start); ?> - Sat <?php echo prDate($prev_end); ?></strong>) and are paid
        on <strong>this</strong> payroll. OT recorded <strong>this</strong> week - including <strong>next SATURDAY</strong> -
        will be paid on the <strong>NEXT</strong> week's payroll.
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-center">#</th>
                    <th>NAME</th>
                    <th>POSITION</th>
                    <th class="text-end">RATE</th>
                    <th class="text-center">DAYS</th>
                    <th class="text-end">BASIC PAY</th>
                    <th class="text-center">OT HRS*</th>
                    <th class="text-end">OT RATE</th>
                    <th class="text-end">OT PAY</th>
                    <th class="text-end">TOTAL</th>
                    <th class="text-end">PER. CASH ADV.</th>
                    <th class="text-end">CASH ADV.</th>
                    <th class="text-end">INCOME</th>
                    <th class="text-end">NET</th>
                    <?php foreach ($day_labels as $dl): ?>
                        <th class="text-center"><?php echo $dl; ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$entries): ?>
                    <tr>
                        <td colspan="21" class="text-center text-muted">No entries yet.</td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($entries as $e): ?>
                        <tr>
                            <td class="text-center"><?php echo $i++; ?></td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($e['name']); ?></td>
                            <td class="small"><?php echo htmlspecialchars($e['position'] ?: '-'); ?></td>
                            <td class="text-end"><?php echo prMoney($e['rate']); ?></td>
                            <td class="text-center"><?php echo $e['days_worked']; ?></td>
                            <td class="text-end"><?php echo prMoney($e['basic']); ?></td>
                            <td class="text-center"><?php echo $e['ot_hours']; ?></td>
                            <td class="text-end"><?php echo prMoney($e['ot_rate']); ?></td>
                            <td class="text-end"><?php echo prMoney($e['ot_pay']); ?></td>
                            <td class="text-end fw-semibold"><?php echo prMoney($e['gross']); ?></td>
                            <td class="text-end"><?php echo prMoney($e['personal_cash_advance']); ?></td>
                            <td class="text-end"><?php echo prMoney($e['cash_advance']); ?></td>
                            <td class="text-end"><?php echo prMoney($e['gross'] - $e['cash_advance'] - $e['personal_cash_advance']); ?></td>
                            <td class="text-end fw-semibold"><?php echo prMoney($e['net']); ?></td>
                            <?php
                                $att = str_split(prNormAtt($e['attendance'] ?? ''));
                                for ($d = 0; $d < 7; $d++) {
                                    $code = htmlspecialchars($att[$d] ?? '-');
                                    echo '<td class="text-center small py-1">' . $code . '</td>';
                                }
                            ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="row justify-content-end mt-3">
        <div class="col-md-5">
            <table class="table table-bordered table-sm mb-0">
                <tbody>
                    <tr>
                        <th class="table-light">TOTAL PAYROLL</th>
                        <td class="text-end fw-semibold"><?php echo prMoney($totals['payroll_total']); ?></td>
                    </tr>
                    <tr>
                        <th class="table-light">CASH ADVANCE (BALI BINYE)</th>
                        <td class="text-end">- <?php echo prMoney($totals['budget']); ?></td>
                    </tr>
                    <tr>
                        <th class="table-light">DEDUCTION</th>
                        <td class="text-end">- <?php echo prMoney($totals['site_deduction']); ?></td>
                    </tr>
                    <tr>
                        <th class="table-light">ADD. EXPENSES</th>
                        <td class="text-end">+ <?php echo prMoney($totals['add_expenses']); ?></td>
                    </tr>
                    <tr class="table-dark">
                        <th>TOTAL TOTAL</th>
                        <td class="text-end fw-bold"><?php echo prMoney($totals['net']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>
