<?php
// E:\PAYROLL\tools\repair_ot_sync.php
// One-time repair: re-snapshot ot_hours + ot_daily of every existing
// payroll_entries row from the PREVIOUS week's DTR rollup (the same rule the
// payroll form uses). Fixes stale zeros left behind when DTR OT was entered
// or changed after a payroll week was already saved.
//
// CLI: php tools/repair_ot_sync.php [--dry]
// Target DB comes from env vars / config exactly like the app (DB_HOST etc.).

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$dry = in_array('--dry', $argv, true);

require_once __DIR__ . '/../config/DBconnect.php';
require_once __DIR__ . '/../config/DBpayroll.php';

$payrolls = dbFetchAll("SELECT * FROM payrolls ORDER BY site_id ASC, week_start ASC");
$scanned = 0;
$fixed = 0;
foreach ($payrolls as $p) {
    $prev_ws = date('Y-m-d', strtotime($p['week_start'] . ' -7 days'));
    $prev_we = date('Y-m-d', strtotime($p['week_end'] . ' -7 days'));
    $rollup = dbWeekAttendanceByWorker((int)$p['site_id'], $prev_ws, $prev_we);
    if (!$rollup) {
        continue;
    }
    foreach (dbGetPayrollEntries((int)$p['id']) as $e) {
        $scanned++;
        $k = (int)$e['site_employee_id'];
        if (!isset($rollup[$k])) {
            continue;
        }
        $ot = round((float)$rollup[$k]['ot_total'], 2);
        $daily = trim((string)$rollup[$k]['ot_daily']);
        $oldOt = (float)$e['ot_hours'];
        // SAFETY: only FILL IN missing OT or normalize formatting. Never
        // lower an existing value -- old weeks whose DTR was wiped must keep
        // their recorded (already paid) OT.
        if ($ot < $oldOt - 0.005) {
            continue;
        }
        if (abs($ot - $oldOt) < 0.005 && trim((string)$e['ot_daily']) === $daily) {
            continue;
        }
        echo sprintf(
            "payroll %d (%s..%s) %s: ot %.2f -> %.2f  daily [%s] -> [%s]\n",
            $p['id'],
            $p['week_start'],
            $p['week_end'],
            (string)$e['name'],
            (float)$e['ot_hours'],
            $ot,
            (string)$e['ot_daily'],
            $daily
        );
        $fixed++;
        if (!$dry) {
            dbUpdate('payroll_entries', ['ot_hours' => $ot, 'ot_daily' => $daily], ['id' => (int)$e['id']]);
        }
    }
}
echo ($dry ? "[DRY] " : "") . "scanned=$scanned fixed=$fixed\n";
