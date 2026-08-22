<?php
// E:\PAYROLL\tools\fill_backlog.php  (DELETE AFTER RUNNING)
// One-time dev/test script: backfills DTR attendance and payroll weeks +
// entries for every site from the week after its last payroll up to the
// CURRENT week, so the DTR / payroll / hub screens have a full backlog to test.
//
// Rules:
//   - Every backfilled week gets full DTR (P Mon..Sun..Sat), sample per-day OT.
//   - Every NON-current backfilled week also gets a payroll week + entries
//     (days + previous week's OT paid, sample cash advance, site budget).
//   - The CURRENT week gets DTR only (up to today) and NO payroll, so you can
//     test the "Add Payroll Week" flow (guards + redirect) live.
//   - Weeks a site already has a payroll for are skipped.
//
// Usage (CLI):
//   php tools/fill_backlog.php           -> DB from env / db_credentials.php
//   php tools/fill_backlog.php --local   -> LOCAL wip0 DB
//   php tools/fill_backlog.php --dry     -> print what it WOULD do, no writes

if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

$args = $_SERVER['argv'];
$is_local = in_array('--local', $args, true);
$is_dry = in_array('--dry', $args, true);

if ($is_local) {
    $_SERVER['SERVER_NAME'] = 'localhost';
}

require_once __DIR__ . '/../src/config/DBgetPDO.php';
require_once __DIR__ . '/../src/config/DBpayroll.php';

$today = date('Y-m-d');
$cur_start = date('Y-m-d', strtotime('last sunday', strtotime($today . ' +1 day')));
$cur_end = date('Y-m-d', strtotime($cur_start . ' +6 days'));

// Deterministic per-day OT pattern per worker+week index (Sun..Sat).
function otPattern($seId, $weekIndex) {
    $pats = [
        [0, 0, 2, 1.5, 0, 0, 2],
        [0, 0, 0, 1.5, 1, 0, 3],
        [0, 1, 1, 1, 1, 0, 0],
    ];
    return $pats[(($seId * 7 + $weekIndex) % count($pats))];
}

// Sample weekly cash advance for a worker (varied, plausible).
function sampleCa($seId) {
    return round((250 + (($seId * 97) % 1750)) / 50) * 50;
}

// Budget per site per week (keeps site totals looking real).
function siteBudget($siteName) {
    $n = strtoupper(trim($siteName));
    if (strpos($n, 'ANGELES') !== false) {
        return 20000;
    }
    if (strpos($n, 'LUBAO') !== false) {
        return 13000;
    }
    return 0;
}

function nextSundayAfter($date) {
    $d = strtotime($date . ' +1 day');
    $dow = (int)date('w', $d);
    return date('Y-m-d', strtotime('+' . ((7 - $dow) % 7) . ' days', $d));
}

function weekList($startSunday, $endSunday) {
    $weeks = [];
    $d = strtotime($startSunday);
    $endTs = strtotime($endSunday);
    while ($d <= $endTs) {
        $ws = date('Y-m-d', $d);
        $weeks[] = ['ws' => $ws, 'we' => date('Y-m-d', strtotime($ws . ' +6 days'))];
        $d = strtotime($ws . ' +7 days');
    }
    return $weeks;
}

echo "Target DB: " . ($is_local ? "LOCAL (wip0)" : "default/configured") . "\n";
echo "Current week: $cur_start .. $cur_end (today = $today)\n";
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

$grand = ['sites' => 0, 'weeks' => 0, 'payrolls' => 0, 'entries' => 0, 'att_rows' => 0];

foreach ($sites as $site) {
    $site_id = (int)$site['id'];
    $site_name = (string)$site['name'];
    $workers = dbGetSiteEmployees($site_id);

    $max_we = dbFetchColumn(
        "SELECT MAX(week_end) FROM payrolls WHERE site_id = :s",
        [':s' => $site_id]
    );
    $start = $max_we ? nextSundayAfter((string)$max_we) : date('Y-m-d', strtotime($cur_start . ' -13 weeks'));

    if ($start > $cur_start) {
        echo "\n[" . $site_name . "] already up to date (newest payroll ends at $max_we) - skip.\n";
        continue;
    }

    $weeks = weekList($start, $cur_start);
    $cnt = ['weeks' => count($weeks), 'payrolls' => 0, 'entries' => 0, 'att' => 0];

    // Per-worker cache of previous week's OT (total + per-day CSV) for "OT paid next week".
    $prev_ot = [];

    echo "\n[" . $site_name . "] (" . count($workers) . " workers, " . count($weeks) . " weeks)\n";

    foreach ($weeks as $i => $week) {
        $ws = $week['ws'];
        $we = $week['we'];
        $is_current = $ws === $cur_start;

        $existing = (int)dbFetchColumn(
            "SELECT COUNT(*) FROM payrolls WHERE site_id = :s AND week_start = :w",
            [':s' => $site_id, ':w' => $ws]
        );
        $pid = null;
        if (!$is_current && !$existing) {
            if (!$is_dry) {
                $pid = dbAddPayroll($site_id, $ws, $we, siteBudget($site_name), 0, 0);
            }
            $cnt['payrolls']++;
        } elseif ($existing) {
            echo "  skip $ws..$we (payroll already exists)\n";
        }

        $attParams = [];
        $entryParams = [];
        $week_att = 0;

        foreach ($workers as $w) {
            $seId = (int)$w['id'];
            $pattern = otPattern($seId, $i);

            $prev = $prev_ot[$seId] ?? ['total' => 0.0, 'csv' => '0,0,0,0,0,0,0'];
            $ot_paid = $prev['total'];
            $ot_daily_csv = count(array_filter(explode(',', $prev['csv']), 'strlen')) ? $prev['csv'] : '0,0,0,0,0,0,0';

            $d = strtotime($ws);
            $att_up_to = $is_current ? min(strtotime($today), strtotime($we)) : strtotime($we);
            $codes = [];
            $daily_ot = [];
            while ($d <= $att_up_to) {
                $date = date('Y-m-d', $d);
                $idx = (int)date('w', $d);
                if (!$is_dry) {
                    array_push($attParams, $seId, $date, 'P', $pattern[$idx], '');
                }
                $codes[$idx] = 'P';
                $daily_ot[$idx] = $pattern[$idx];
                $d = strtotime($date . ' +1 day');
                $week_att++;
            }

            $attendance = '';
            for ($k = 0; $k < 7; $k++) {
                $attendance .= isset($codes[$k]) ? 'P' : '.';
            }
            $ot_daily_this = implode(',', array_map(fn($k) => (string)($daily_ot[$k] ?? 0), range(0, 6)));

            if (!$is_current && $pid) {
                $cnt['entries']++;
                if (!$is_dry) {
                    array_push(
                        $entryParams,
                        $pid, $seId, 7.0, $ot_paid, sampleCa($seId), 0,
                        0, 0,
                        (string)$w['position'], (float)$w['rate'],
                        $attendance, $ot_daily_csv
                    );
                }
            }

            $prev_ot[$seId] = ['total' => array_sum($pattern), 'csv' => $ot_daily_this];
        }

        $cnt['att'] += $week_att;

        if ($attParams && !$is_dry) {
            $n = intdiv(count($attParams), 5);
            $upsert = dbDriver() === 'pgsql'
                ? " ON CONFLICT (site_employee_id, work_date) DO UPDATE SET status = EXCLUDED.status, ot_hours = EXCLUDED.ot_hours"
                : " ON DUPLICATE KEY UPDATE status = VALUES(status), ot_hours = VALUES(ot_hours)";
            dbExecute(
                "INSERT INTO attendance (site_employee_id, work_date, status, ot_hours, note) VALUES "
                . implode(',', array_fill(0, $n, '(?,?,?,?,?)'))
                . $upsert,
                $attParams
            );
        }
        if ($entryParams && !$is_dry) {
            $n = intdiv(count($entryParams), 12);
            $upsert = dbDriver() === 'pgsql'
                ? " ON CONFLICT (payroll_id, site_employee_id) DO UPDATE SET days_worked = EXCLUDED.days_worked, ot_hours = EXCLUDED.ot_hours, cash_advance = EXCLUDED.cash_advance, personal_cash_advance = EXCLUDED.personal_cash_advance, deduction = EXCLUDED.deduction, flat_pay = EXCLUDED.flat_pay, position = EXCLUDED.position, rate = EXCLUDED.rate, attendance = EXCLUDED.attendance, ot_daily = EXCLUDED.ot_daily"
                : " ON DUPLICATE KEY UPDATE days_worked = VALUES(days_worked), ot_hours = VALUES(ot_hours), cash_advance = VALUES(cash_advance), personal_cash_advance = VALUES(personal_cash_advance), deduction = VALUES(deduction), flat_pay = VALUES(flat_pay), position = VALUES(position), rate = VALUES(rate), attendance = VALUES(attendance), ot_daily = VALUES(ot_daily)";
            dbExecute(
                "INSERT INTO payroll_entries (payroll_id, site_employee_id, days_worked, ot_hours, cash_advance, personal_cash_advance, deduction, flat_pay, position, rate, attendance, ot_daily) VALUES "
                . implode(',', array_fill(0, $n, '(?,?,?,?,?,?,?,?,?,?,?,?)'))
                . $upsert,
                $entryParams
            );
        }
    }

    echo "  -> weeks=" . $cnt['weeks'] . " payrolls=" . $cnt['payrolls'] . " entries=" . $cnt['entries'] . " att_rows=" . $cnt['att'] . "\n";
    $grand['sites']++;
    $grand['weeks'] += $cnt['weeks'];
    $grand['payrolls'] += $cnt['payrolls'];
    $grand['entries'] += $cnt['entries'];
    $grand['att_rows'] += $cnt['att'];
}

echo "\nTOTAL: " . $grand['sites'] . " site(s), " . $grand['weeks'] . " week(s), "
    . $grand['payrolls'] . " payroll(s), " . $grand['entries'] . " entry(ies), "
    . $grand['att_rows'] . " attendance row(s).\n";
echo ($is_dry ? "DRY RUN - nothing was written.\n" : "Done. Delete this script after use.\n");