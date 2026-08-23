<?php
/**
 * Payroll database functions - Sites, Employees, Weekly Payrolls
 * Built on top of the generic helpers in config/DBgetPDO.php
 * File: config/DBpayroll.php
 */

require_once __DIR__ . '/DBgetPDO.php';

// ============================================================
// SCHEMA MIGRATION
// ============================================================

/**
 * Translate the canonical (MySQL-flavoured) DDL used across this app into
 * the equivalent PostgreSQL DDL, so one schema definition serves both drivers.
 */
function dbDdlForDriver($mysqlDdl) {
    if (dbDriver() !== 'pgsql') {
        return $mysqlDdl;
    }
    $pg = str_replace(' INT AUTO_INCREMENT PRIMARY KEY', ' SERIAL PRIMARY KEY', $mysqlDdl);
    // UNIQUE KEY uniq_name (a, b)  ->  UNIQUE (a, b)
    $pg = preg_replace('/UNIQUE KEY\s+\S+\s*\(/', 'UNIQUE (', $pg);
    // PostgreSQL has no table options suffix.
    $pg = str_replace(') ENGINE=InnoDB', ')', $pg);
    return $pg;
}

/**
 * Ensure payroll tables/columns exist for an older DB that predates a schema
 * change (CREATE TABLE IF NOT EXISTS never alters existing tables). Call once
 * per page load; harmless if everything is already up to date.
 */
function dbEnsurePayrollSchema() {
    // Self-heal only matters on first touch or after a schema change - not on
    // every page load. Throttle: once per session hour, and warm workers
    // skip entirely for 60s. A failed run sets no flags, so it retries.
    static $worker_ok_until = 0;
    if (time() < $worker_ok_until) {
        return;
    }
    if (isset($_SESSION['schema_ok_until']) && time() < (int)$_SESSION['schema_ok_until']) {
        $worker_ok_until = time() + 60;
        return;
    }
    $mark_ok = function () use (&$worker_ok_until) {
        $_SESSION['schema_ok_until'] = time() + 3600;
        $worker_ok_until = time() + 60;
    };
    $schema = [
        "CREATE TABLE IF NOT EXISTS sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS site_employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_id INT NOT NULL,
            employee_id INT NOT NULL,
            position VARCHAR(100) NOT NULL DEFAULT '',
            rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_site_employee (site_id, employee_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS payrolls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_id INT NOT NULL,
            week_start DATE NOT NULL,
            week_end DATE NOT NULL,
            budget DECIMAL(12,2) NOT NULL DEFAULT 0,
            site_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            add_expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_payroll_week (site_id, week_start, week_end)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS payroll_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payroll_id INT NOT NULL,
            site_employee_id INT NOT NULL,
            days_worked DECIMAL(5,1) NOT NULL DEFAULT 0,
            ot_hours DECIMAL(5,1) NOT NULL DEFAULT 0,
            cash_advance DECIMAL(12,2) NOT NULL DEFAULT 0,
            personal_cash_advance DECIMAL(12,2) NOT NULL DEFAULT 0,
            deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            flat_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            position VARCHAR(100) NOT NULL DEFAULT '',
            rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            attendance VARCHAR(32) NOT NULL DEFAULT '',
            ot_daily VARCHAR(32) NOT NULL DEFAULT '',
            UNIQUE KEY uniq_entry (payroll_id, site_employee_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS personal_cash_advances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_employee_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            advance_date DATE NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS worker_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_employee_id INT NOT NULL,
            to_site_id INT NOT NULL,
            days DECIMAL(5,1) NOT NULL DEFAULT 0,
            week_start DATE NOT NULL,
            week_end DATE NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_employee_id INT NOT NULL,
            work_date DATE NOT NULL,
            status VARCHAR(1) NOT NULL DEFAULT '.',
            ot_hours DECIMAL(5,1) NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_att_day (site_employee_id, work_date)
        ) ENGINE=InnoDB",
    ];
    try {
        dbEnsureUserRoleColumn();
        $existing = dbExistingTables(['payroll_entries', 'personal_cash_advances', 'worker_transfers', 'attendance']);
        if (!in_array('payroll_entries', $existing, true)) {
            foreach ($schema as $sql) {
                dbExecute(dbDdlForDriver($sql));
            }
            $mark_ok();
            return;
        }
        // Existing payroll tables: create only the ones still missing.
        $late_tables = [
            'personal_cash_advances' => "CREATE TABLE IF NOT EXISTS personal_cash_advances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_employee_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                advance_date DATE NOT NULL,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'worker_transfers' => "CREATE TABLE IF NOT EXISTS worker_transfers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_employee_id INT NOT NULL,
                to_site_id INT NOT NULL,
                days DECIMAL(5,1) NOT NULL DEFAULT 0,
                week_start DATE NOT NULL,
                week_end DATE NOT NULL,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            'attendance' => "CREATE TABLE IF NOT EXISTS attendance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_employee_id INT NOT NULL,
                work_date DATE NOT NULL,
                status VARCHAR(1) NOT NULL DEFAULT '.',
                ot_hours DECIMAL(5,1) NOT NULL DEFAULT 0,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_att_day (site_employee_id, work_date)
            ) ENGINE=InnoDB",
        ];
        foreach ($late_tables as $name => $ddl) {
            if (!in_array($name, $existing, true)) {
                dbExecute(dbDdlForDriver($ddl));
            }
        }
        if (!dbColumnExists('payroll_entries', 'personal_cash_advance')) {
            $after = dbDriver() === 'pgsql' ? '' : ' AFTER cash_advance';
            dbExecute("ALTER TABLE payroll_entries
                ADD COLUMN personal_cash_advance DECIMAL(12,2) NOT NULL DEFAULT 0" . $after);
        }
        if (!dbColumnExists('payroll_entries', 'ot_daily')) {
            $after = dbDriver() === 'pgsql' ? '' : ' AFTER attendance';
            dbExecute("ALTER TABLE payroll_entries
                ADD COLUMN ot_daily VARCHAR(32) NOT NULL DEFAULT ''" . $after);
        }
        $mark_ok();
    } catch (PDOException $e) {
        error_log('dbEnsurePayrollSchema: ' . $e->getMessage());
    }
}

// ============================================================
// SITES
// ============================================================

/**
 * Get all sites with worker and payroll counts
 */
function dbGetSites() {
    $rows = dbFetchAll(
        "SELECT s.*,
            (SELECT COUNT(*) FROM site_employees se WHERE se.site_id = s.id) AS worker_count,
            (SELECT COUNT(*) FROM payrolls p WHERE p.site_id = s.id) AS payroll_count
         FROM sites s ORDER BY s.name ASC"
    );
    return $rows ?: [];
}

function dbGetSite($site_id) {
    return dbFetchOne("SELECT * FROM sites WHERE id = :id", [':id' => $site_id]);
}

function dbAddSite($name) {
    return dbInsert('sites', ['name' => $name]);
}

function dbUpdateSite($site_id, $name) {
    return dbUpdate('sites', ['name' => $name], ['id' => $site_id]);
}

function dbDeleteSite($site_id) {
    $payrolls = dbFetchAll("SELECT id FROM payrolls WHERE site_id = :site_id", [':site_id' => $site_id]);
    foreach ($payrolls as $p) {
        dbDelete('payroll_entries', ['payroll_id' => $p['id']]);
    }
    dbExecute("DELETE FROM attendance WHERE site_employee_id IN (SELECT id FROM site_employees WHERE site_id = :sid)", [':sid' => (int)$site_id]);
    dbDelete('payrolls', ['site_id' => $site_id]);
    dbDelete('site_employees', ['site_id' => $site_id]);
    dbDelete('sites', ['id' => $site_id]);
}

// ============================================================
// EMPLOYEES (global, one record per name)
// ============================================================

function dbFindEmployeeByName($name) {
    return dbFetchOne("SELECT * FROM employees WHERE name = :name", [':name' => $name]);
}

function dbGetOrCreateEmployee($name) {
    $employee = dbFindEmployeeByName($name);
    if ($employee) {
        return (int)$employee['id'];
    }
    return (int)dbInsert('employees', ['name' => $name]);
}

// ============================================================
// SITE EMPLOYEES (a worker assigned to a site with position + rate)
// ============================================================

function dbGetSiteEmployees($site_id) {
    $rows = dbFetchAll(
        "SELECT se.*, e.name
         FROM site_employees se
         JOIN employees e ON e.id = se.employee_id
         WHERE se.site_id = :site_id
         ORDER BY e.name ASC",
        [':site_id' => $site_id]
    );
    return $rows ?: [];
}

function dbGetSiteEmployee($id) {
    return dbFetchOne(
        "SELECT se.*, e.name
         FROM site_employees se
         JOIN employees e ON e.id = se.employee_id
         WHERE se.id = :id",
        [':id' => $id]
    );
}

function dbGetSiteEmployeeByEmployee($site_id, $employee_id) {
    return dbFetchOne(
        "SELECT * FROM site_employees WHERE site_id = :site_id AND employee_id = :employee_id",
        [':site_id' => $site_id, ':employee_id' => $employee_id]
    );
}

function dbAddSiteEmployee($site_id, $employee_id, $position, $rate) {
    return dbInsert('site_employees', [
        'site_id' => $site_id,
        'employee_id' => $employee_id,
        'position' => $position,
        'rate' => $rate
    ]);
}

function dbUpdateSiteEmployee($id, $position, $rate) {
    return dbUpdate('site_employees', ['position' => $position, 'rate' => $rate], ['id' => $id]);
}

function dbDeleteSiteEmployee($id) {
    dbDelete('payroll_entries', ['site_employee_id' => $id]);
    dbDelete('attendance', ['site_employee_id' => $id]);
    dbDelete('site_employees', ['id' => $id]);
}

// ============================================================
// PAYROLLS (a weekly payroll period for a site)
// ============================================================

function dbGetPayrolls($site_id) {
    $rows = dbFetchAll(
        "SELECT p.*,
            (SELECT COUNT(*) FROM payroll_entries pe2 WHERE pe2.payroll_id = p.id) AS entry_count,
            COALESCE((
                SELECT ROUND(SUM(
                    CASE WHEN pe2.flat_pay > 0 THEN pe2.flat_pay
                         ELSE COALESCE(NULLIF(pe2.rate, 0), se2.rate) * pe2.days_worked
                              + (COALESCE(NULLIF(pe2.rate, 0), se2.rate) / 8) * pe2.ot_hours
                    END), 2)
                FROM payroll_entries pe2
                JOIN site_employees se2 ON se2.id = pe2.site_employee_id
                WHERE pe2.payroll_id = p.id
            ), 0) AS payroll_total
         FROM payrolls p
         WHERE p.site_id = :site_id
         ORDER BY p.week_start DESC",
        [':site_id' => $site_id]
    );
    return $rows ?: [];
}

function dbGetPayroll($id) {
    return dbFetchOne("SELECT * FROM payrolls WHERE id = :id", [':id' => $id]);
}

/**
 * Newest $limit payroll weeks for EVERY site in ONE round-trip.
 * Uses a window function (PostgreSQL + MySQL 8+/MariaDB 10.2+).
 * Returns: [ site_id => ['site_name','worker_count','weeks'=>[...]] ]
 */
function dbRecentPayrollsPerSite($limit = 5) {
    $rows = dbFetchAll(
        "SELECT ranked.*, s.name AS site_name,
            (SELECT COUNT(*) FROM site_employees se WHERE se.site_id = ranked.site_id) AS worker_count,
            (SELECT COUNT(*) FROM payroll_entries pe WHERE pe.payroll_id = ranked.id) AS entry_count,
            COALESCE((
                SELECT ROUND(SUM(
                    CASE WHEN le.flat_pay > 0 THEN le.flat_pay
                         ELSE COALESCE(NULLIF(le.rate, 0), lse.rate) * le.days_worked
                              + (COALESCE(NULLIF(le.rate, 0), lse.rate) / 8) * le.ot_hours
                    END), 2)
                FROM payroll_entries le
                JOIN site_employees lse ON lse.id = le.site_employee_id
                WHERE le.payroll_id = ranked.id
            ), 0) AS payroll_total
         FROM (
             SELECT x.*, ROW_NUMBER() OVER (PARTITION BY x.site_id ORDER BY x.week_start DESC) AS rn
             FROM payrolls x
         ) ranked
         JOIN sites s ON s.id = ranked.site_id
         WHERE ranked.rn <= :lim
         ORDER BY s.name ASC, ranked.week_start DESC",
        [':lim' => max(1, (int)$limit)]
    ) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $sid = (int)$r['site_id'];
        if (!isset($out[$sid])) {
            $out[$sid] = [
                'site_name'    => $r['site_name'],
                'worker_count' => (int)$r['worker_count'],
                'weeks'        => [],
            ];
        }
        unset($r['rn'], $r['site_name'], $r['worker_count']);
        $out[$sid]['weeks'][] = $r;
    }
    return $out;
}

function dbGetPayrollByWeek($site_id, $week_start, $week_end) {
    return dbFetchOne(
        "SELECT * FROM payrolls WHERE site_id = :site_id AND week_start = :ws AND week_end = :we",
        [':site_id' => $site_id, ':ws' => $week_start, ':we' => $week_end]
    );
}

function dbAddPayroll($site_id, $week_start, $week_end, $budget, $site_deduction, $add_expenses) {
    return dbInsert('payrolls', [
        'site_id' => $site_id,
        'week_start' => $week_start,
        'week_end' => $week_end,
        'budget' => $budget,
        'site_deduction' => $site_deduction,
        'add_expenses' => $add_expenses
    ]);
}

function dbUpdatePayroll($id, $week_start, $week_end, $budget, $site_deduction, $add_expenses) {
    return dbUpdate('payrolls', [
        'week_start' => $week_start,
        'week_end' => $week_end,
        'budget' => $budget,
        'site_deduction' => $site_deduction,
        'add_expenses' => $add_expenses
    ], ['id' => $id]);
}

function dbDeletePayroll($id) {
    dbDelete('payroll_entries', ['payroll_id' => $id]);
    dbDelete('payrolls', ['id' => $id]);
}

// ============================================================
// PAYROLL ENTRIES (per worker, per week)
// ============================================================

function dbGetPayrollEntries($payroll_id) {
    $rows = dbFetchAll(
        "SELECT pe.*, e.name,
            COALESCE(pe.position, se.position) AS position,
            COALESCE(NULLIF(pe.rate, 0), se.rate) AS rate
         FROM payroll_entries pe
         JOIN site_employees se ON se.id = pe.site_employee_id
         JOIN employees e ON e.id = se.employee_id
         WHERE pe.payroll_id = :payroll_id
         ORDER BY e.name ASC",
        [':payroll_id' => $payroll_id]
    );
    return $rows ?: [];
}

/**
 * Upsert one payroll entry. Attendance is preserved from the existing row
 * when not provided (the entry form does not edit attendance).
 * $flat_pay: if > 0, overrides all time-based math (worker paid a fixed amount).
 * $position / $rate: snapshot of the worker's role/pay at this week so old
 * payrolls stay correct even if the site worker is later edited.
 */
function dbSavePayrollEntry($payroll_id, $site_employee_id, $days, $ot, $cash_advance, $deduction, $attendance = null, $flat_pay = 0, $position = '', $rate = 0, $personal_cash_advance = 0, $ot_daily = '') {
    $data = [
        'days_worked' => (float)$days,
        'ot_hours' => (float)$ot,
        'cash_advance' => (float)$cash_advance,
        'personal_cash_advance' => (float)$personal_cash_advance,
        'deduction' => (float)$deduction,
        'flat_pay' => (float)$flat_pay,
        'position' => (string)$position,
        'rate' => (float)$rate,
        'ot_daily' => (string)$ot_daily
    ];
    $existing = dbFetchOne(
        "SELECT id, attendance FROM payroll_entries WHERE payroll_id = :p AND site_employee_id = :s",
        [':p' => $payroll_id, ':s' => $site_employee_id]
    );
    if ($existing) {
        $data['attendance'] = $attendance !== null ? $attendance : ($existing['attendance'] ?? '');
        return (bool)dbUpdate('payroll_entries', $data, ['id' => $existing['id']]);
    }
    $data['payroll_id'] = $payroll_id;
    $data['site_employee_id'] = $site_employee_id;
    $data['attendance'] = $attendance ?? '';
    return (bool)dbInsert('payroll_entries', $data);
}

// ============================================================
// CALCULATIONS (mirrors the payroll spreadsheet)
// OT rate = daily rate / 8; basic = rate x days; OT pay = OT rate x OT hours
// ============================================================

/**
 * Compute the derived money values for one worker.
 * A positive $flat_pay overrides the time-based math (fixed weekly pay).
 * @return array ot_rate, basic, ot_pay, gross
 */
function prEntryCalc($rate, $days, $ot, $flat_pay = 0) {
    $flat_pay = (float)$flat_pay;
    if ($flat_pay > 0) {
        return ['ot_rate' => 0, 'basic' => 0, 'ot_pay' => 0, 'gross' => round($flat_pay, 2)];
    }
    $rate = (float)$rate;
    $days = (float)$days;
    $ot = (float)$ot;
    $ot_rate = round($rate / 8, 4);
    $basic = round($rate * $days, 2);
    $ot_pay = round($ot_rate * $ot, 2);
    $gross = round($basic + $ot_pay, 2);
    return [
        'ot_rate' => $ot_rate,
        'basic' => $basic,
        'ot_pay' => $ot_pay,
        'gross' => $gross
    ];
}

/**
 * Add computed columns (ot_rate, basic, ot_pay, gross, net) to entries.
 */
function prWithCalc($entries) {
    foreach ($entries as &$e) {
        $c = prEntryCalc($e['rate'], $e['days_worked'], $e['ot_hours'], $e['flat_pay'] ?? 0);
        $e = array_merge($e, $c);
        $e['cash_advance'] = (float)$e['cash_advance'];
        $e['personal_cash_advance'] = (float)($e['personal_cash_advance'] ?? 0);
        $e['net'] = round($e['gross'] - $e['cash_advance'] - $e['personal_cash_advance'], 2);
    }
    unset($e);
    return $entries;
}

/**
 * Site-level summary for one weekly payroll:
 *   TOTAL PAYROLL = sum(gross)
 *   CASH ADVANCE  = budget (engineer cash advance "BALI BINYE NA ENGR")
 *   TOTAL TOTAL   = TOTAL PAYROLL - budget - site_deduction + add_expenses
 */
function prPayrollTotals($entries, $payroll) {
    $payroll_total = 0;
    $ca_total = 0;
    $ded_total = 0;
    $worker_net_total = 0;
    foreach ($entries as $e) {
        $payroll_total += $e['gross'];
        $ca_total += $e['cash_advance'];
        $ded_total += $e['deduction'];
        $worker_net_total += $e['net'];
    }
    $budget = (float)$payroll['budget'];
    $site_ded = (float)$payroll['site_deduction'];
    $add = (float)$payroll['add_expenses'];
    $net = round($payroll_total - $budget - $site_ded + $add, 2);
    return [
        'payroll_total' => round($payroll_total, 2),
        'cash_advance_total' => round($ca_total, 2),
        'deduction_total' => round($ded_total, 2),
        'worker_net_total' => round($worker_net_total, 2),
        'budget' => $budget,
        'site_deduction' => $site_ded,
        'add_expenses' => $add,
        'net' => $net
    ];
}

function prMoney($v) {
    return number_format((float)$v, 2);
}

function prDate($d) {
    return date('M d, Y', strtotime($d));
}

/**
 * Normalize a stored 7-char attendance code to P/A/H/. codes
 * (old seed data stores digits for present days).
 */
function prNormAtt($att) {
    $out = [];
    $att = strtoupper((string)$att);
    for ($d = 0; $d < 7; $d++) {
        $c = substr($att, $d, 1);
        if ($c === 'H' || $c === 'A') {
            $out[] = $c;
        } elseif ($c === 'P' || ($c !== '' && $c !== '.' && ctype_digit($c))) {
            $out[] = 'P';
        } else {
            $out[] = '.';
        }
    }
    return implode('', $out);
}

/**
 * Split the stored ot_daily CSV (7 values) into an array of floats.
 */
function prOtDailyArray($ot_daily) {
    $parts = explode(',', (string)$ot_daily);
    $out = [];
    for ($d = 0; $d < 7; $d++) {
        $out[] = isset($parts[$d]) ? (float)$parts[$d] : 0;
    }
    return $out;
}

// ============================================================
// DASHBOARD TOTALS
// ============================================================

function dbPayrollGrandTotals() {
    // Single round-trip: all counts + grand total as one row of subselects.
    $row = dbFetchOne(
        "SELECT
            (SELECT COUNT(*) FROM sites) AS site_count,
            (SELECT COUNT(*) FROM site_employees) AS worker_count,
            (SELECT COUNT(*) FROM payrolls) AS payroll_count,
            (SELECT COUNT(*) FROM payroll_entries) AS entry_count,
            (SELECT COALESCE(ROUND(SUM(
                CASE WHEN pe.flat_pay > 0 THEN pe.flat_pay
                     ELSE COALESCE(NULLIF(pe.rate, 0), se.rate) * pe.days_worked
                          + (COALESCE(NULLIF(pe.rate, 0), se.rate) / 8) * pe.ot_hours
                END), 2), 0)
             FROM payroll_entries pe
             JOIN site_employees se ON se.id = pe.site_employee_id) AS total_payroll"
    ) ?: [];

    return [
        'site_count'    => (int)($row['site_count'] ?? 0),
        'worker_count'  => (int)($row['worker_count'] ?? 0),
        'payroll_count' => (int)($row['payroll_count'] ?? 0),
        'entry_count'   => (int)($row['entry_count'] ?? 0),
        'total_payroll' => (float)($row['total_payroll'] ?? 0),
    ];
}

// ============================================================
// PERSONAL CASH ADVANCE LEDGER
// ============================================================

/**
 * Record a personal cash advance given to a worker.
 */
function dbAddPersonalCashAdvance($site_employee_id, $amount, $advance_date, $note = '') {
    return dbInsert('personal_cash_advances', [
        'site_employee_id' => (int)$site_employee_id,
        'amount' => (float)$amount,
        'advance_date' => (string)$advance_date,
        'note' => (string)$note
    ]);
}

function dbDeletePersonalCashAdvance($id) {
    return (bool)dbDelete('personal_cash_advances', ['id' => (int)$id]);
}

function dbGetPersonalCashAdvances($site_employee_id) {
    $rows = dbFetchAll(
        "SELECT * FROM personal_cash_advances
         WHERE site_employee_id = :se
         ORDER BY advance_date DESC, id DESC",
        [':se' => (int)$site_employee_id]
    );
    return $rows ?: [];
}

/**
 * Running balance = total advances given - total recovered from weekly
 * Per. Cash Adv. payroll entries for this worker.
 */
function dbPersonalCaBalance($site_employee_id) {
    $given = (float)dbFetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM personal_cash_advances
         WHERE site_employee_id = :se",
        [':se' => (int)$site_employee_id]
    );
    $recovered = (float)dbFetchColumn(
        "SELECT COALESCE(SUM(pe.personal_cash_advance), 0)
         FROM payroll_entries pe
         JOIN payrolls p ON p.id = pe.payroll_id
         WHERE pe.site_employee_id = :se",
        [':se' => (int)$site_employee_id]
    );
    return round($given - $recovered, 2);
}

// ============================================================
// SITE TRANSFERS
// ============================================================

/**
 * Record a transfer of a worker from their current site to another site.
 * Also ensures the worker is assigned to the target site and has a blank
 * payroll entry for the week so their transferred days can be entered there.
 */
function dbAddWorkerTransfer($site_employee_id, $to_site_id, $days, $week_start, $week_end, $note = '') {
    $se = dbGetSiteEmployee((int)$site_employee_id);
    if (!$se) {
        return false;
    }

    // Ensure the worker is assigned to the target site (copy position + rate).
    $target = dbGetSiteEmployeeByEmployee((int)$to_site_id, (int)$se['employee_id']);
    if (!$target) {
        $target_id = dbAddSiteEmployee((int)$to_site_id, (int)$se['employee_id'], $se['position'], $se['rate']);
    } else {
        $target_id = (int)$target['id'];
    }

    // Ensure the target site has a payroll for this week.
    $payroll = dbGetPayrollByWeek((int)$to_site_id, $week_start, $week_end);
    if (!$payroll) {
        $payroll_id = dbAddPayroll((int)$to_site_id, $week_start, $week_end, 0, 0, 0);
    } else {
        $payroll_id = (int)$payroll['id'];
    }

    // Add a blank entry for the week so the worker appears in the target form.
    $existing = dbFetchOne(
        "SELECT id FROM payroll_entries WHERE payroll_id = :p AND site_employee_id = :s",
        [':p' => $payroll_id, ':s' => $target_id]
    );
    if (!$existing) {
        dbSavePayrollEntry($payroll_id, $target_id, 0, 0, 0, 0, '', 0, $se['position'], $se['rate'], 0, '');
    }

    return dbInsert('worker_transfers', [
        'site_employee_id' => (int)$site_employee_id,
        'to_site_id' => (int)$to_site_id,
        'days' => (float)$days,
        'week_start' => (string)$week_start,
        'week_end' => (string)$week_end,
        'note' => (string)$note
    ]);
}

function dbGetWorkerTransfers($site_employee_id = null) {
    $sql = "SELECT t.*, s.name AS from_site, ts.name AS to_site, e.name AS worker_name
            FROM worker_transfers t
            JOIN site_employees se ON se.id = t.site_employee_id
            JOIN employees e ON e.id = se.employee_id
            JOIN sites s ON s.id = se.site_id
            JOIN sites ts ON ts.id = t.to_site_id";
    $params = [];
    if ($site_employee_id) {
        $sql .= " WHERE t.site_employee_id = :se";
        $params[':se'] = (int)$site_employee_id;
    }
    $sql .= " ORDER BY t.created_at DESC";
    return dbFetchAll($sql, $params) ?: [];
}

// ============================================================
// DAILY ATTENDANCE (DTR)
// ============================================================

/**
 * Upsert one worker's attendance for a single day. A cleared row
 * (status '.', 0 OT, no note) removes the entry for that day.
 */
function dbSaveAttendance($site_employee_id, $work_date, $status, $ot_hours, $note = '') {
    $status = strtoupper(substr((string)$status, 0, 1));
    $status = in_array($status, ['P', 'A', 'H', '.'], true) ? $status : '.';
    $ot_hours = (float)$ot_hours;
    $note = trim((string)$note);
    $site_employee_id = (int)$site_employee_id;

    if ($status === '.' && $ot_hours <= 0 && $note === '') {
        return dbDelete('attendance', ['site_employee_id' => $site_employee_id, 'work_date' => $work_date]);
    }

    $existing = dbFetchOne(
        "SELECT id FROM attendance WHERE site_employee_id = :s AND work_date = :d",
        [':s' => $site_employee_id, ':d' => $work_date]
    );
    if ($existing) {
        dbUpdate('attendance', [
            'status' => $status,
            'ot_hours' => $ot_hours,
            'note' => $note
        ], ['id' => $existing['id']]);
        return (int)$existing['id'];
    }
    return (int)dbInsert('attendance', [
        'site_employee_id' => $site_employee_id,
        'work_date' => $work_date,
        'status' => $status,
        'ot_hours' => $ot_hours,
        'note' => $note
    ]);
}

/**
 * All site workers with their attendance row for one date (LEFT JOIN).
 */
function dbGetAttendanceForDate($site_id, $work_date) {
    $rows = dbFetchAll(
        "SELECT se.id AS site_employee_id, e.name, se.position, se.rate,
                a.status, a.ot_hours, a.note
         FROM site_employees se
         JOIN employees e ON e.id = se.employee_id
         LEFT JOIN attendance a ON a.site_employee_id = se.id AND a.work_date = :d
         WHERE se.site_id = :site_id
         ORDER BY e.name ASC",
        [':site_id' => (int)$site_id, ':d' => $work_date]
    );
    return $rows ?: [];
}

/**
 * Attendance rollup for a date range, keyed by site_employee_id.
 * Each worker: 7-char status codes (in date order), days (P=1,H=0.5),
 * ot_total, and ot_daily (7 daily OT values as a CSV).
 */
function dbWeekAttendanceByWorker($site_id, $week_start, $week_end) {
    $workers = dbGetSiteEmployees((int)$site_id);
    $att = dbFetchAll(
        "SELECT site_employee_id, work_date, status, ot_hours
         FROM attendance
         WHERE work_date BETWEEN :ws AND :we
           AND site_employee_id IN (SELECT id FROM site_employees WHERE site_id = :site_id)
         ORDER BY work_date ASC",
        [':ws' => $week_start, ':we' => $week_end, ':site_id' => (int)$site_id]
    );

    $map = [];
    $dates = [];
    $d = $week_start;
    while ($d <= $week_end) {
        $dates[] = $d;
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }

    foreach ($workers as $w) {
        $map[(int)$w['id']] = [
            'codes' => str_repeat('.', 7),
            'days' => 0.0,
            'ot_total' => 0.0,
            'ot_daily' => [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]
        ];
    }

    foreach ($att as $row) {
        $se = (int)$row['site_employee_id'];
        if (!isset($map[$se])) {
            continue;
        }
        $idx = array_search($row['work_date'], $dates, true);
        if ($idx === false) {
            continue;
        }
        $status = strtoupper((string)$row['status']);
        $code = in_array($status, ['P', 'A', 'H', '.'], true) ? $status : '.';
        $map[$se]['codes'][$idx] = $code;
        if ($code === 'P') $map[$se]['days'] += 1;
        elseif ($code === 'H') $map[$se]['days'] += 0.5;
        $map[$se]['ot_daily'][$idx] += (float)$row['ot_hours'];
    }

    foreach ($map as &$m) {
        $m['days'] = round($m['days'], 1);
        $m['ot_total'] = round(array_sum($m['ot_daily']), 2);
        $m['ot_daily'] = implode(',', array_map(function ($v) {
            return sprintf('%g', $v);
        }, $m['ot_daily']));
    }
    unset($m);
    return $map;
}

// ============================================================
// PAYROLL HUB (payroll.php): site summary + advance history
// ============================================================

/**
 * All sites with worker/payroll counts plus their most recent payroll week
 * (id, range, budget, payroll total, saved-entry count). Used by the Payroll
 * hub site boxes.
 */
function dbSitesWithLatestPayroll() {
    // Called by the topbar AND page bodies; memoize per request so we pay
    // one WAN round-trip instead of two-plus.
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $rows = dbFetchAll(
        "SELECT s.*,
            (SELECT COUNT(*) FROM site_employees se WHERE se.site_id = s.id) AS worker_count,
            (SELECT COUNT(*) FROM payrolls p WHERE p.site_id = s.id) AS payroll_count,
            lp.id AS latest_payroll_id,
            lp.week_start AS latest_week_start,
            lp.week_end AS latest_week_end,
            lp.budget AS latest_budget,
            COALESCE((
                SELECT ROUND(SUM(
                    CASE WHEN le.flat_pay > 0 THEN le.flat_pay
                         ELSE COALESCE(NULLIF(le.rate, 0), lse.rate) * le.days_worked
                              + (COALESCE(NULLIF(le.rate, 0), lse.rate) / 8) * le.ot_hours
                    END), 2)
                FROM payroll_entries le
                JOIN site_employees lse ON lse.id = le.site_employee_id
         WHERE le.payroll_id = lp.id
             ), 0) AS latest_total,
            (SELECT COUNT(*) FROM payroll_entries le2 WHERE le2.payroll_id = lp.id) AS latest_entries
         FROM sites s
         LEFT JOIN payrolls lp ON lp.id = (
             SELECT p2.id FROM payrolls p2
             WHERE p2.site_id = s.id
             ORDER BY p2.week_start DESC LIMIT 1
         )
         ORDER BY s.name ASC"
    ) ?: [];
    $memo = $rows;
    return $memo;
}

/**
 * Regular weekly cash advances recovered per worker per week
 * (payroll_entries.cash_advance > 0), for the Payroll hub history.
 */
function dbCashAdvanceHistory() {
    return dbFetchAll(
        "SELECT pe.cash_advance, p.id AS payroll_id, p.week_start, p.week_end,
            s.id AS site_id, s.name AS site_name,
            se.id AS site_employee_id, e.name AS worker_name, e.id AS employee_id
         FROM payroll_entries pe
         JOIN payrolls p ON p.id = pe.payroll_id
         JOIN site_employees se ON se.id = pe.site_employee_id
         JOIN employees e ON e.id = se.employee_id
         JOIN sites s ON s.id = p.site_id
         WHERE pe.cash_advance > 0
         ORDER BY p.week_start DESC, s.name ASC, e.name ASC"
    ) ?: [];
}

/**
 * Personal cash advance ledger (advances given) for every worker, including
 * each worker's running balance = given - recovered. Sorted newest first.
 */
function dbPersonalCaHistoryAll() {
    $rows = dbFetchAll(
        "SELECT pca.id, pca.amount, pca.advance_date, pca.note, pca.created_at,
            se.id AS site_employee_id, e.name AS worker_name, e.id AS employee_id,
            s.id AS site_id, s.name AS site_name
         FROM personal_cash_advances pca
         JOIN site_employees se ON se.id = pca.site_employee_id
         JOIN employees e ON e.id = se.employee_id
         JOIN sites s ON s.id = se.site_id
         ORDER BY pca.advance_date DESC, pca.id DESC"
    ) ?: [];

    $balances = [];
    $givens = [];
    foreach (array_unique(array_column($rows, 'site_employee_id')) as $se) {
        $se = (int)$se;
        $balances[$se] = dbPersonalCaBalance($se);
        $givens[$se] = 0.0;
    }
    foreach ($rows as $r) {
        $givens[(int)$r['site_employee_id']] += (float)$r['amount'];
    }
    foreach ($rows as &$r) {
        $se = (int)$r['site_employee_id'];
        $r['balance'] = $balances[$se];
        $r['recovered'] = round($givens[$se] - $balances[$se], 2);
        $r['status'] = $balances[$se] > 0 ? 'pending' : 'done';
    }
    unset($r);
    return $rows;
}

/**
 * Weeks where a worker actually repaid personal cash advance
 * (payroll_entries.personal_cash_advance > 0), for the Payroll hub history.
 */
function dbPersonalCaRecoveryHistory() {
    return dbFetchAll(
        "SELECT pe.personal_cash_advance AS recovered, p.id AS payroll_id,
            p.week_start, p.week_end,
            s.id AS site_id, s.name AS site_name,
            se.id AS site_employee_id, e.name AS worker_name, e.id AS employee_id
         FROM payroll_entries pe
         JOIN payrolls p ON p.id = pe.payroll_id
         JOIN site_employees se ON se.id = pe.site_employee_id
         JOIN employees e ON e.id = se.employee_id
         JOIN sites s ON s.id = p.site_id
         WHERE pe.personal_cash_advance > 0
         ORDER BY p.week_start DESC, s.name ASC, e.name ASC"
    ) ?: [];
}

/**
 * Total DTR attendance rows saved for a site within a week (any day has data).
 */
function dbWeekAttendanceCount($site_id, $week_start, $week_end) {
    return (int)dbFetchColumn(
        "SELECT COUNT(*) FROM attendance a
         JOIN site_employees se ON se.id = a.site_employee_id
         WHERE se.site_id = :site_id AND a.work_date BETWEEN :ws AND :we",
        [':site_id' => (int)$site_id, ':ws' => $week_start, ':we' => $week_end]
    );
}

/**
 * The most recent payroll week for a site (or null).
 */
function dbLatestPayrollForSite($site_id) {
    return dbFetchOne(
        "SELECT * FROM payrolls WHERE site_id = :site_id
         ORDER BY week_start DESC LIMIT 1",
        [':site_id' => (int)$site_id]
    );
}
