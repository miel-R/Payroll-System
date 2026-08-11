<?php
// E:\PAYROLL\dtr.php
// Daily Time Record: pick a site + a day, then mark each worker
// P/A/H and their OT hours for that day. Payroll days and OT are
// derived from these entries (OT is paid on the next payroll).

$page_title = 'Daily Time Record';
$active_page = 'dtr';
require_once __DIR__ . '/inc/header.php';

$site_id = (int)($_GET['site_id'] ?? 0);
$flash = [];

try {
    $sites = dbGetSites();
} catch (PDOException $e) {
    $sites = [];
}

if (!$site_id) {
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0"><i class="bi bi-clipboard-check"></i> Daily Time Record</h3>
            <small class="text-muted">Pick a site to record the workers' attendance for the day.</small>
        </div>
    </div>

    <?php foreach ($flash as $f): ?>
        <div class="alert alert-<?php echo $f[0]; ?> flash-toast"><?php echo $f[1]; ?></div>
    <?php endforeach; ?>

    <?php if (!$sites): ?>
        <div class="alert alert-warning">
            No sites yet. <a href="sites.php" class="alert-link">Add a site</a> first.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($sites as $s): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="border rounded p-3 mb-3">
                        <a href="dtr.php?site_id=<?php echo (int)$s['id']; ?>" class="fw-semibold">
                            <i class="bi bi-building"></i> <?php echo htmlspecialchars($s['name']); ?>
                        </a>
                        <div class="text-muted small"><?php echo (int)$s['worker_count']; ?> workers</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
    require_once __DIR__ . '/inc/footer.php';
    exit();
}

try {
    $site = dbGetSite($site_id);
} catch (PDOException $e) {
    $site = null;
}
if (!$site) {
    header('Location: dtr.php');
    exit();
}

$date = (string)($_GET['date'] ?? '');
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Week (Sun..Sat) that contains the selected date.
$ts = strtotime($date);
$dow = (int)date('w', $ts);
$week_start = date('Y-m-d', $ts - $dow * 86400);
$week_end = date('Y-m-d', $ts + (6 - $dow) * 86400);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $workers = dbGetSiteEmployees($site_id);
        $saved = 0;
        foreach ($workers as $w) {
            $k = (int)$w['id'];
            $status = trim((string)($_POST['att_' . $k] ?? '.'));
            $ot = (float)($_POST['otd_' . $k] ?? 0);
            $note = trim((string)($_POST['note_' . $k] ?? ''));
            if (dbSaveAttendance($k, $date, $status, $ot, $note)) {
                $saved++;
            }
        }
        $flash[] = ['success', 'Attendance saved for ' . date('M d, Y', strtotime($date)) . '.'];
    }
}

$workers = dbGetSiteEmployees($site_id);
$attendance = dbGetAttendanceForDate($site_id, $date);

$att_map = [];
foreach ($attendance as $a) {
    $att_map[(int)$a['site_employee_id']] = $a;
}

// Per-day fill counts for the week strip.
$day_counts = [];
try {
    $day_rows = dbFetchAll(
        "SELECT work_date, COUNT(*) AS c
         FROM attendance
         WHERE work_date BETWEEN :ws AND :we
           AND site_employee_id IN (SELECT id FROM site_employees WHERE site_id = :sid)
         GROUP BY work_date",
        [':ws' => $week_start, ':we' => $week_end, ':sid' => $site_id]
    );
    foreach ($day_rows as $r) {
        $day_counts[$r['work_date']] = (int)$r['c'];
    }
} catch (PDOException $e) {
    $day_counts = [];
}

$day_labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
$week_days = [];
$d = $week_start;
while ($d <= $week_end) {
    $week_days[] = $d;
    $d = date('Y-m-d', strtotime($d . ' +1 day'));
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="dtr.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left"></i> All Sites</a>
        <h3 class="mb-0"><?php echo htmlspecialchars($site['name']); ?> - Daily Attendance</h3>
    </div>
    <div class="text-end">
        <a href="payrolls.php?site_id=<?php echo $site_id; ?>" class="btn btn-sm btn-outline-primary">
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
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="d-flex gap-2">
            <a href="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo date('Y-m-d', strtotime($date . ' -1 day')); ?>"
                class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i> Prev</a>
            <a href="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo date('Y-m-d'); ?>"
                class="btn btn-outline-primary btn-sm">Today</a>
            <a href="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo date('Y-m-d', strtotime($date . ' +1 day')); ?>"
                class="btn btn-outline-secondary btn-sm">Next <i class="bi bi-chevron-right"></i></a>
        </div>
        <form method="GET" action="dtr.php" class="d-flex align-items-center gap-2">
            <input type="hidden" name="site_id" value="<?php echo $site_id; ?>">
            <label class="form-label mb-0 small">Date</label>
            <input type="date" class="form-control form-control-sm" name="date" value="<?php echo htmlspecialchars($date); ?>">
            <button type="submit" class="btn btn-sm btn-primary">Go</button>
        </form>
    </div>

    <div class="row g-2 mb-3 week-strip">
        <?php foreach ($week_days as $i => $day): ?>
            <?php
                $is_selected = $day === $date;
                $is_today = $day === date('Y-m-d');
                $filled = isset($day_counts[$day]) ? $day_counts[$day] : 0;
                $btn_class = $is_selected ? 'btn-dark' : ($filled > 0 ? 'btn-outline-success' : 'btn-outline-secondary');
            ?>
            <div class="col">
                <a href="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo $day; ?>"
                    class="btn btn-sm w-100 <?php echo $btn_class; ?>">
                    <div><?php echo $day_labels[$i]; ?></div>
                    <div><?php echo (int)date('j', strtotime($day)); ?></div>
                    <div class="small" style="font-size:0.7em">
                        <?php echo $filled > 0 ? $filled . ' set' : ''; ?>
                        <?php echo $is_today ? 'today' : ''; ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <h5 class="mb-2"><?php echo date('l, F j, Y', strtotime($date)); ?></h5>

    <?php if (!$workers): ?>
        <div class="alert alert-warning mb-0">
            No workers assigned to this site yet.
            <a href="site_workers.php?site_id=<?php echo $site_id; ?>" class="alert-link">Add workers</a> first.
        </div>
    <?php else: ?>
        <form method="POST" action="dtr.php?site_id=<?php echo $site_id; ?>&date=<?php echo htmlspecialchars($date); ?>"
            data-ajax>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">

            <div class="table-responsive d-none d-lg-block dtr-table">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Worker</th>
                            <th>Position</th>
                            <th class="text-end">Rate</th>
                            <th class="text-center">Status</th>
                            <th class="text-center" style="min-width:110px">OT Hours</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $a):
                            $k = (int)$a['site_employee_id'];
                            $status = strtoupper((string)($a['status'] ?? '.'));
                            $status = in_array($status, ['P', 'A', 'H', '.'], true) ? $status : '.';
                        ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($a['name']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($a['position'] ?: '-'); ?></td>
                                <td class="text-end"><?php echo prMoney($a['rate']); ?></td>
                                <td>
                                    <select class="form-select form-select-sm text-center" name="att_<?php echo $k; ?>">
                                        <?php foreach (['P' => 'P', 'A' => 'A', 'H' => 'H', '.' => '.'] as $cv => $cl):
                                            $sel = $status === $cv ? 'selected' : ''; ?>
                                            <option value="<?php echo $cv; ?>" <?php echo $sel; ?>><?php echo $cl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.5" min="0" class="form-control form-control-sm text-center"
                                        name="otd_<?php echo $k; ?>" value="<?php echo (float)($a['ot_hours'] ?? 0); ?>">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" name="note_<?php echo $k; ?>"
                                        value="<?php echo htmlspecialchars($a['note'] ?? ''); ?>" placeholder="optional">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-lg-none">
                <?php foreach ($attendance as $a):
                    $k = (int)$a['site_employee_id'];
                    $status = strtoupper((string)($a['status'] ?? '.'));
                    $status = in_array($status, ['P', 'A', 'H', '.'], true) ? $status : '.';
                ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="fw-semibold"><?php echo htmlspecialchars($a['name']); ?></div>
                            <div class="text-muted small">
                                <?php echo htmlspecialchars($a['position'] ?: '-'); ?> &middot;
                                <?php echo prMoney($a['rate']); ?>/day
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-4">
                                <label class="form-label small mb-0">Status</label>
                                <select class="form-select form-select-sm text-center" name="att_<?php echo $k; ?>">
                                    <?php foreach (['P' => 'P', 'A' => 'A', 'H' => 'H', '.' => '.'] as $cv => $cl):
                                        $sel = $status === $cv ? 'selected' : ''; ?>
                                        <option value="<?php echo $cv; ?>" <?php echo $sel; ?>><?php echo $cl; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-4">
                                <label class="form-label small mb-0">OT Hours</label>
                                <input type="number" step="0.5" min="0" class="form-control form-control-sm text-center"
                                    name="otd_<?php echo $k; ?>" value="<?php echo (float)($a['ot_hours'] ?? 0); ?>">
                            </div>
                            <div class="col-4">
                                <label class="form-label small mb-0">Note</label>
                                <input type="text" class="form-control form-control-sm" name="note_<?php echo $k; ?>"
                                    value="<?php echo htmlspecialchars($a['note'] ?? ''); ?>" placeholder="opt.">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save <?php echo date('M d', strtotime($date)); ?></button>
        </form>
        <p class="text-muted small mt-3 mb-0">
            Code: <code>P</code> = present, <code>H</code> = half day, <code>A</code> = absent, <code>.</code> = no data.
            OT hours recorded today are paid on the <strong>next</strong> payroll.
            <span class="d-none d-lg-inline">Keyboard: click a row's status or OT field and press
                <code>P</code>/<code>A</code>/<code>H</code>/<code>.</code> to set it.</span>
        </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
