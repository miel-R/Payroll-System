<?php
// E:\PAYROLL\payroll_view.php
// Printable weekly payroll report, mirroring the source spreadsheet layout.

$page_title = 'Payroll Report';
$active_page = 'sites';
require_once __DIR__ . '/inc/header.php';

$payroll_id = (int)($_GET['payroll_id'] ?? 0);

try {
    $payroll = dbGetPayroll($payroll_id);
} catch (PDOException $e) {
    $payroll = null;
}

if (!$payroll) {
    header('Location: sites.php');
    exit();
}

$site = dbGetSite((int)$payroll['site_id']);
$entries = prWithCalc(dbGetPayrollEntries($payroll_id));
$totals = prPayrollTotals($entries, $payroll);

$day_labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
?>

<div class="no-print d-flex justify-content-between align-items-center mb-3">
    <a href="payroll_form.php?payroll_id=<?php echo $payroll_id; ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Edit Entries
    </a>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
        <i class="bi bi-printer"></i> Print / Save PDF
    </button>
</div>

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
            <div class="text-muted small" style="font-size:0.75em">* OT hrs are the previous week's DTR OT, paid on this payroll.</div>
        </div>
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

<?php require_once __DIR__ . '/inc/footer.php'; ?>
