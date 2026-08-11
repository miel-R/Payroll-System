<?php
// E:\PAYROLL\payroll_form.php
// ADMIN ONLY: edit the weekly payroll entries.
// Days (Sun..Sat) are edited here directly and written back to the DTR;
// OT hours paid are the PREVIOUS week's DTR OT (OT is paid next payroll).
// Side actions: Personal Cash Advance ledger + transfer to another site.
// Finance role is redirected to the read-only view.

$page_title = 'Edit Payroll Entries';
$active_page = 'sites';
require_once __DIR__ . '/inc/header.php';
requireRole('admin');

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
$site_id = (int)$site['id'];
$workers = dbGetSiteEmployees($site_id);
$all_sites = dbGetSites();

$flash = [];
$day_labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

function prEntriesByWorker($payroll_id) {
    $map = [];
    foreach (dbGetPayrollEntries($payroll_id) as $e) {
        $map[(int)$e['site_employee_id']] = $e;
    }
    return $map;
}

$entries_by_se = prEntriesByWorker($payroll_id);

// Attendance rollups: this week (for days) and the previous week (for OT).
$week_start = $payroll['week_start'];
$week_end = $payroll['week_end'];
$prev_start = date('Y-m-d', strtotime($week_start . ' -7 days'));
$prev_end = date('Y-m-d', strtotime($week_end . ' -7 days'));

try {
    $week_att = dbWeekAttendanceByWorker($site_id, $week_start, $week_end);
    $prev_att = dbWeekAttendanceByWorker($site_id, $prev_start, $prev_end);
} catch (PDOException $e) {
    $week_att = [];
    $prev_att = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $changes = 0;
            // The 7 dates of this payroll week (Sun..Sat) - the admin grid edits
            // this week's DTR attendance directly, so saving writes it back.
            $week_dates = [];
            for ($i = 0; $i < 7; $i++) {
                $week_dates[] = date('Y-m-d', strtotime($week_start . " +$i days"));
            }
            foreach ($workers as $w) {
                $k = (int)$w['id'];
                $pa = $prev_att[$k] ?? ['codes' => '.......', 'days' => 0.0, 'ot_total' => 0.0, 'ot_daily' => '0,0,0,0,0,0,0'];

                $codes = [];
                foreach ($week_dates as $idx => $date) {
                    $c = strtoupper(substr(trim((string)($_POST['att_' . $k . '_' . $idx] ?? '')), 0, 1));
                    $c = in_array($c, ['P', 'A', 'H', '.'], true) ? $c : '.';
                    $codes[] = $c;
                    $otd = (float)($_POST['otd_' . $k . '_' . $idx] ?? 0);
                    dbSaveAttendance($k, $date, $c, $otd);
                }
                $att = implode('', $codes);
                $days = 0;
                foreach ($codes as $c) {
                    if ($c === 'P') {
                        $days += 1;
                    } elseif ($c === 'H') {
                        $days += 0.5;
                    }
                }
                $ot_daily = $pa['ot_daily'];
                $ot = (float)$pa['ot_total'];

                $ca = (float)($_POST['ca_' . $k] ?? 0);
                $pca = (float)($_POST['pca_' . $k] ?? 0);
                $ded = (float)($_POST['ded_' . $k] ?? 0);
                $flat = (float)($_POST['flat_' . $k] ?? 0);

                $hasEntry = isset($entries_by_se[$k]);
                $isEmpty = $days == 0 && $ot == 0 && $ca == 0 && $pca == 0 && $ded == 0 && $flat == 0;

                if ($isEmpty) {
                    if ($hasEntry) {
                        dbDelete('payroll_entries', ['id' => $entries_by_se[$k]['id']]);
                        $changes++;
                    }
                    continue;
                }
                dbSavePayrollEntry($payroll_id, $k, $days, $ot, $ca, $ded, $att, $flat, $w['position'], $w['rate'], $pca, $ot_daily);
                $changes++;
            }
            $flash[] = ['success', 'Saved ' . $changes . ' entry update(s) and DTR attendance.'];
            $entries_by_se = prEntriesByWorker($payroll_id);
        } elseif ($action === 'add_pca') {
            $k = (int)($_POST['se_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $advance_date = trim($_POST['advance_date'] ?? '');
            $note = trim($_POST['note'] ?? '');
            if ($k > 0 && $amount > 0 && $advance_date !== '') {
                dbAddPersonalCashAdvance($k, $amount, $advance_date, $note);
                $flash[] = ['success', 'Personal cash advance recorded.'];
            } else {
                $flash[] = ['danger', 'Amount and date are required.'];
            }
        } elseif ($action === 'delete_pca') {
            $id = (int)($_POST['pca_id'] ?? 0);
            if ($id > 0) {
                dbDeletePersonalCashAdvance($id);
                $flash[] = ['success', 'Personal cash advance entry deleted.'];
            }
        } elseif ($action === 'transfer') {
            $k = (int)($_POST['se_id'] ?? 0);
            $to_site_id = (int)($_POST['to_site_id'] ?? 0);
            $days = (float)($_POST['days'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($k > 0 && $to_site_id > 0 && $days > 0) {
                dbAddWorkerTransfer($k, $to_site_id, $days, $payroll['week_start'], $payroll['week_end'], $note);
                $flash[] = ['success', 'Worker transferred to the other site for ' . $days . ' day(s).'];
            } else {
                $flash[] = ['danger', 'Target site and days are required.'];
            }
        }
    } catch (PDOException $e) {
        $flash[] = ['danger', 'Could not save: ' . htmlspecialchars($e->getMessage())];
    }
}

// Derived totals for display.
$entries = array_values($entries_by_se);
$entries = prWithCalc($entries);
$totals = prPayrollTotals($entries, $payroll);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="payrolls.php?site_id=<?php echo $site_id; ?>" class="btn btn-sm btn-outline-secondary mb-2">
            <i class="bi bi-arrow-left"></i> <?php echo htmlspecialchars($site['name']); ?> - Payrolls
        </a>
        <h3 class="mb-0"><?php echo prDate($payroll['week_start']); ?> - <?php echo prDate($payroll['week_end']); ?></h3>
        <small class="text-muted">
            Days (P/A/H/.) and this week's OT are edited here and written back to the DTR.
            OT Hrs <span class="text-muted">paid</span> are the previous week's DTR OT (OT is paid next payroll).
            <a href="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo $week_start; ?>">Open DTR</a>
        </small>
    </div>
    <div class="text-end">
        <a href="site_workers.php?site_id=<?php echo $site_id; ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-person-plus"></i> Add Worker
        </a>
        <a href="payroll_view.php?payroll_id=<?php echo $payroll_id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i> View / Print
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
    <h4><i class="bi bi-calculator"></i> Summary</h4>
    <div class="row text-center">
        <div class="col-6 col-md-2">
            <div class="border rounded p-2 mb-2">
                <small class="text-muted d-block">Payroll</small>
                <strong id="sPayroll"><?php echo prMoney($totals['payroll_total']); ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="border rounded p-2 mb-2">
                <small class="text-muted d-block">Budget</small>
                <strong><?php echo prMoney($totals['budget']); ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="border rounded p-2 mb-2">
                <small class="text-muted d-block">Site Deduction</small>
                <strong><?php echo prMoney($totals['site_deduction']); ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="border rounded p-2 mb-2">
                <small class="text-muted d-block">Add. Expenses</small>
                <strong><?php echo prMoney($totals['add_expenses']); ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="border rounded p-2 mb-2">
                <small class="text-muted d-block">Worker CA</small>
                <strong><?php echo prMoney($totals['cash_advance_total']); ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="border rounded p-2 bg-dark text-white">
                <small class="opacity-75 d-block">Net</small>
                <strong id="sNet"><?php echo prMoney($totals['net']); ?></strong>
            </div>
        </div>
    </div>
</div>

<div class="content-card">
    <h4><i class="bi bi-list-ol"></i> Worker Entries</h4>
    <?php if (!$workers): ?>
        <div class="alert alert-warning mb-0">
            No workers assigned to this site yet.
            <a href="site_workers.php?site_id=<?php echo $site_id; ?>" class="alert-link">Add workers</a> first.
        </div>
    <?php else: ?>
        <form method="POST" action="payroll_form.php?payroll_id=<?php echo $payroll_id; ?>" id="entryForm" data-ajax>
            <input type="hidden" name="action" value="save">
            <?php echo csrf_field(); ?>
            <div class="row g-3">
                <?php foreach ($workers as $w):
                    $k = (int)$w['id'];
                    $e = $entries_by_se[$k] ?? null;
                    $rate = (float)$w['rate'];
                    $wa = $week_att[$k] ?? ['codes' => '.......', 'days' => 0.0, 'ot_total' => 0.0, 'ot_daily' => '0,0,0,0,0,0,0'];
                    $pa = $prev_att[$k] ?? ['codes' => '.......', 'days' => 0.0, 'ot_total' => 0.0, 'ot_daily' => '0,0,0,0,0,0,0'];
                    $days = (float)$wa['days'];
                    $ot = (float)$pa['ot_total'];
                    $att = str_pad((string)$wa['codes'], 7, '.');
                    $ot_daily_arr = prOtDailyArray($wa['ot_daily'] ?? '0,0,0,0,0,0,0');
                    $has_dtr = str_replace('.', '', $att) !== '';
                    $ca = $e ? (float)$e['cash_advance'] : 0;
                    $pca = $e ? (float)$e['personal_cash_advance'] : 0;
                    $ded = $e ? (float)$e['deduction'] : 0;
                    $flat = $e ? (float)$e['flat_pay'] : 0;
                ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="worker-card border rounded p-3 h-100"
                            data-rate="<?php echo $rate; ?>" data-days="<?php echo $days; ?>" data-ot="<?php echo $ot; ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="me-2">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($w['name']); ?></div>
                                    <div class="text-muted small">
                                        <?php echo htmlspecialchars($w['position'] ?: '-'); ?> &middot;
                                        <?php echo prMoney($rate); ?>/day
                                    </div>
                                </div>
                                <div class="text-end small">
                                    <div class="text-muted">Gross</div>
                                    <div class="fw-semibold cell-gross">0.00</div>
                                    <div class="text-muted mt-1">Net</div>
                                    <div class="fw-semibold text-success cell-net">0.00</div>
                                </div>
                            </div>

                            <div class="mb-2">
                                <?php if (!$has_dtr): ?>
                                    <div class="alert alert-warning py-1 px-2 small mb-1">
                                        No DTR attendance for this week.
                                        <a href="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo $week_start; ?>"
                                            class="alert-link">Enter in DTR</a>
                                    </div>
                                <?php endif; ?>
                                <div class="row g-1 text-center">
                                    <?php for ($d = 0; $d < 7; $d++): ?>
                                        <div class="col">
                                            <div class="small text-muted"><?php echo $day_labels[$d]; ?></div>
                                            <select class="form-select form-select-sm text-center p-1 day-select in-att"
                                                name="att_<?php echo $k; ?>_<?php echo $d; ?>">
                                                <option value="P" <?php echo $att[$d] === 'P' ? 'selected' : ''; ?>>P</option>
                                                <option value="A" <?php echo $att[$d] === 'A' ? 'selected' : ''; ?>>A</option>
                                                <option value="H" <?php echo $att[$d] === 'H' ? 'selected' : ''; ?>>H</option>
                                                <option value="." <?php echo ($att[$d] === '.' || $att[$d] === '') ? 'selected' : ''; ?>>.</option>
                                            </select>
                                            <input type="number" step="0.5" min="0" class="form-control form-control-sm text-center mt-1 p-1 in-otd"
                                                name="otd_<?php echo $k; ?>_<?php echo $d; ?>"
                                                value="<?php echo (float)($ot_daily_arr[$d] ?? 0); ?>">
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="d-flex justify-content-between small mt-1">
                                    <span>Days: <strong class="cell-days"><?php echo $days; ?></strong></span>
                                    <span>OT Hrs <span class="text-muted">(prev wk)</span>: <strong class="cell-ot"><?php echo $ot; ?></strong></span>
                                </div>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small mb-0">Cash Adv.</label>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end in-ca"
                                        name="ca_<?php echo $k; ?>" value="<?php echo $ca; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-0">Per. Cash Adv.</label>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end in-pca"
                                        name="pca_<?php echo $k; ?>" value="<?php echo $pca; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-0">Deduction</label>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end in-ded"
                                        name="ded_<?php echo $k; ?>" value="<?php echo $ded; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-0">Flat Pay</label>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end in-flat"
                                        name="flat_<?php echo $k; ?>" value="<?php echo $flat; ?>">
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-info flex-fill" data-bs-toggle="modal"
                                    data-bs-target="#pcaModal<?php echo $k; ?>">
                                    <i class="bi bi-wallet2"></i> Personal CA
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning flex-fill" data-bs-toggle="modal"
                                    data-bs-target="#xferModal<?php echo $k; ?>">
                                    <i class="bi bi-arrow-left-right"></i> Transfer
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Entries
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">Reset</button>
            </div>
        </form>
        <p class="text-muted small mt-3 mb-0">
            Days (P/A/H/.) and this week's OT are editable here and saved back to the
            <a href="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo $week_start; ?>">DTR</a>.
            OT Hrs shown as paid come from the previous week's recorded OT.
        </p>
    <?php endif; ?>
</div>

<?php // Personal Cash Advance modals ?>
<?php foreach ($workers as $w):
    $k = (int)$w['id'];
    $advances = dbGetPersonalCashAdvances($k);
    $balance = dbPersonalCaBalance($k);
?>
    <div class="modal fade" id="pcaModal<?php echo $k; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-wallet2"></i> Personal Cash Advance - <?php echo htmlspecialchars($w['name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Running balance</span>
                        <strong>PHP <?php echo prMoney($balance); ?></strong>
                    </div>

                    <h6 class="text-muted">Add an advance</h6>
                    <form method="POST" action="payroll_form.php?payroll_id=<?php echo $payroll_id; ?>" data-ajax>
                        <input type="hidden" name="action" value="add_pca">
                        <input type="hidden" name="se_id" value="<?php echo $k; ?>">
                        <?php echo csrf_field(); ?>
                        <div class="row g-2">
                            <div class="col-5">
                                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" name="amount"
                                    placeholder="Amount" required>
                            </div>
                            <div class="col-4">
                                <input type="date" class="form-control form-control-sm" name="advance_date" required>
                            </div>
                            <div class="col-3">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Add</button>
                            </div>
                        </div>
                        <input type="text" class="form-control form-control-sm mt-2" name="note" placeholder="Note (optional)">
                    </form>

                    <hr>
                    <h6 class="text-muted">History</h6>
                    <?php if (!$advances): ?>
                        <p class="text-muted small mb-0">No advances recorded yet.</p>
                    <?php else: ?>
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr><th>Date</th><th class="text-end">Amount</th><th>Note</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($advances as $a): ?>
                                    <tr>
                                        <td class="small"><?php echo htmlspecialchars($a['advance_date']); ?></td>
                                        <td class="text-end"><?php echo prMoney($a['amount']); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($a['note'] ?: '-'); ?></td>
                                        <td class="text-end">
                                            <form method="POST" action="payroll_form.php?payroll_id=<?php echo $payroll_id; ?>" class="d-inline"
                                                data-ajax data-confirm="Delete this advance?">
                                                <input type="hidden" name="action" value="delete_pca">
                                                <input type="hidden" name="pca_id" value="<?php echo (int)$a['id']; ?>">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php // Transfer modals ?>
<?php foreach ($workers as $w):
    $k = (int)$w['id'];
?>
    <div class="modal fade" id="xferModal<?php echo $k; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Transfer Worker - <?php echo htmlspecialchars($w['name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Moves this worker to the selected site for the current week so their days can be entered there.</p>
                    <form method="POST" action="payroll_form.php?payroll_id=<?php echo $payroll_id; ?>" data-ajax>
                        <input type="hidden" name="action" value="transfer">
                        <input type="hidden" name="se_id" value="<?php echo $k; ?>">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Transfer to site</label>
                            <select class="form-select" name="to_site_id" required>
                                <option value="">-- Select site --</option>
                                <?php foreach ($all_sites as $s):
                                    if ((int)$s['id'] === $site_id) continue; ?>
                                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Number of days at the other site</label>
                            <input type="number" step="0.5" min="0.5" class="form-control" name="days" value="1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note (optional)</label>
                            <input type="text" class="form-control" name="note" placeholder="e.g. loaned for 3 days">
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-left-right"></i> Transfer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
