<?php
// E:\PAYROLL\tools\fill_test_dtr.php  (DELETE AFTER RUNNING)
// One-time dev/test script: fills DTR attendance for ALL workers on ALL sites
// so the DTR + payroll screens can be tested.
//
// Usage (CLI):
//   php tools/fill_test_dtr.php            -> PRODUCTION (InfinityFree) DB
//   php tools/fill_test_dtr.php --local    -> LOCAL wip0 DB
//   php tools/fill_test_dtr.php --dry      -> print what it WOULD do, no writes
//
// Fills previous week (all 7 days P + sample OT) and the current week
// (Sun..today = P + sample OT). Uses dbSaveAttendance (upsert).

if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

$args = $_SERVER['argv'];
$is_local = in_array('--local', $args, true);
$is_dry = in_array('--dry', $args, true);

if ($is_local) {
    $_SERVER['SERVER_NAME'] = 'localhost';
}

require_once __DIR__ . '/../config/DBgetPDO.php';
require_once __DIR__ . '/../config/DBpayroll.php';

$today = date('Y-m-d');

// Current week: Sun..Sat containing today.
$dow = (int)date('w');
$cur_start = date('Y-m-d', strtotime($today . ' -' . $dow . ' days'));
$cur_end = date('Y-m-d', strtotime($cur_start . ' +6 days'));

// Previous week: Sun..Sat before the current week.
$prev_start = date('Y-m-d', strtotime($cur_start . ' -7 days'));
$prev_end = date('Y-m-d', strtotime($cur_start . ' -1 days'));

// Per-day OT hours by weekday index (Sun=0..Sat=6) for the PREVIOUS week,
// so the current payroll shows OT to pay.
$prev_ot = [0, 0, 2, 1, 0, 0, 3]; // Wed +2h, Thu +1h, Sat +3h
$cur_ot = [0, 0, 2, 0, 0, 1, 0];  // Wed +2h, Fri +1h (paid next week)

function fillWeek($worker_id, $start, $end, $ot_by_dow, $up_to_today, $today, &$counts) {
    $d = strtotime($start);
    $endTs = strtotime($end);
    $todayTs = strtotime($today);
    while ($d <= $endTs) {
        $date = date('Y-m-d', $d);
        if ($up_to_today && $date > $today) {
            break;
        }
        $idx = (int)date('w', $d);
        $ot = $ot_by_dow[$idx] ?? 0;
        dbSaveAttendance($worker_id, $date, 'P', $ot);
        $counts['rows']++;
        $counts['ot_hours'] += $ot;
        $d = strtotime(date('Y-m-d', $d) . ' +1 day');
    }
}

echo "Target DB: " . ($is_local ? "LOCAL (wip0)" : "PRODUCTION (InfinityFree)") . "\n";
echo "Prev week: $prev_start .. $prev_end\n";
echo "Curr week: $cur_start .. $cur_end (today = $today)\n";
echo ($is_dry ? "DRY RUN - no writes.\n" : "RUNNING - this writes to the DB.\n");

try {
    $sites = dbGetSites();
} catch (PDOException $e) {
    die("Could not load sites: " . $e->getMessage() . "\n");
}

if (!$sites) {
    echo "No sites found - nothing to fill.\n";
    exit(0);
}

$grand = ['rows' => 0, 'ot_hours' => 0, 'workers' => 0, 'sites' => 0];

foreach ($sites as $site) {
    $site_id = (int)$site['id'];
    $workers = dbGetSiteEmployees($site_id);
    $site_count = ['rows' => 0, 'ot_hours' => 0, 'workers' => 0];
    echo "\n[" . $site['name'] . "] (" . count($workers) . " workers)\n";

    foreach ($workers as $w) {
        $wid = (int)$w['id'];
        if ($is_dry) {
            $site_count['rows'] += 7 + $dow + 1;
            $site_count['ot_hours'] += array_sum($prev_ot) + array_sum($cur_ot);
            $site_count['workers']++;
            continue;
        }
        fillWeek($wid, $prev_start, $prev_end, $prev_ot, false, $today, $site_count);
        fillWeek($wid, $cur_start, $cur_end, $cur_ot, true, $today, $site_count);
        $site_count['workers']++;
        echo "  - " . $w['name'] . "\n";
    }

    echo "  -> rows=" . $site_count['rows'] . " ot=" . $site_count['ot_hours'] . "h workers=" . $site_count['workers'] . "\n";
    $grand['rows'] += $site_count['rows'];
    $grand['ot_hours'] += $site_count['ot_hours'];
    $grand['workers'] += $site_count['workers'];
    $grand['sites']++;
}

echo "\nTOTAL: " . $grand['sites'] . " site(s), " . $grand['workers'] . " worker(s), "
    . $grand['rows'] . " attendance row(s), " . $grand['ot_hours'] . " OT hour(s).\n";
echo ($is_dry ? "DRY RUN - nothing was written.\n" : "Done. Delete this script after use.\n");
