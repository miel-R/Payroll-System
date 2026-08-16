<?php
// E:\PAYROLL\config\actions.php
//
// Centralized action handlers. Replaces the per-page "if ($action === ...)"
// POST blocks: every page's save/delete/edit flow AND the JSON AJAX API
// (ajax.php) call run_action() so the business logic lives in exactly one
// place.
//
// Each handler returns:
//   ['ok' => bool, 'type' => 'success'|'danger'|'warning', 'msg' => string,
//    'render' => 'refresh'|'dtr_day'|null, 'data' => array]
//
// 'msg' is RAW (unescaped) so toasts render correctly; pages must run it
// through htmlspecialchars() when embedding it in HTML for the no-JS path.

require_once __DIR__ . '/DBpayroll.php';
require_once __DIR__ . '/PDF.php';

function act_ok($type, $msg, $render = null, $data = []) {
    return ['ok' => true, 'type' => $type, 'msg' => $msg, 'render' => $render, 'data' => $data];
}

function act_fail($type, $msg, $render = null, $data = []) {
    return ['ok' => false, 'type' => $type, 'msg' => $msg, 'render' => $render, 'data' => $data];
}

// ============================================================
// DTR read/query helpers (day rows + per-day week rollup)
// ============================================================

function dtr_week_dates($week_start) {
    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $dates[] = date('Y-m-d', strtotime((string)$week_start . " +$i days"));
    }
    return $dates;
}

/**
 * Per-day filled counts + total OT hours for one site's week.
 * @return array of ['date','filled','ot_total'] (Sun..Sat order)
 */
function dtr_week_rollup($site_id, $week_start) {
    $dates = dtr_week_dates($week_start);
    $week_end = $dates[6];
    $out = [];
    try {
        $rows = dbFetchAll(
            "SELECT work_date, COUNT(*) AS c, COALESCE(SUM(ot_hours), 0) AS ot
             FROM attendance
             WHERE work_date BETWEEN :ws AND :we
               AND site_employee_id IN (SELECT id FROM site_employees WHERE site_id = :sid)
             GROUP BY work_date",
            [':ws' => $week_start, ':we' => $week_end, ':sid' => (int)$site_id]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['work_date']] = $r;
        }
        foreach ($dates as $d) {
            $out[] = [
                'date' => $d,
                'filled' => isset($map[$d]) ? (int)$map[$d]['c'] : 0,
                'ot_total' => isset($map[$d]) ? (float)$map[$d]['ot'] : 0.0
            ];
        }
    } catch (PDOException $e) {
        foreach ($dates as $d) {
            $out[] = ['date' => $d, 'filled' => 0, 'ot_total' => 0.0];
        }
    }
    return $out;
}

/**
 * Full per-day payload the DTR UI (server render, API) uses to paint the
 * day table + week strip: all workers with their row, per-day counts and
 * the day summary.
 */
function dtr_day_payload($site_id, $date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        return null;
    }
    $ts = strtotime($date);
    $dow = (int)date('w', $ts);
    $week_start = date('Y-m-d', $ts - $dow * 86400);

    $att = dbGetAttendanceForDate((int)$site_id, (string)$date);
    $day = [];
    $set = 0;
    $ot_total = 0.0;
    foreach ($att as $a) {
        $status = strtoupper((string)($a['status'] ?? '.'));
        $status = in_array($status, ['P', 'A', 'H', '.'], true) ? $status : '.';
        $row_ot = (float)($a['ot_hours'] ?? 0);
        $note = (string)($a['note'] ?? '');
        if ($status !== '.' || $row_ot > 0 || $note !== '') {
            $set++;
            $ot_total += $row_ot;
        }
        $day[] = [
            'site_employee_id' => (int)$a['site_employee_id'],
            'name' => (string)$a['name'],
            'position' => (string)($a['position'] ?? ''),
            'rate' => (float)$a['rate'],
            'status' => $status,
            'ot_hours' => $row_ot,
            'note' => $note
        ];
    }

    return [
        'site_id' => (int)$site_id,
        'date' => $date,
        'week_start' => $week_start,
        'week_end' => date('Y-m-d', $ts + (6 - $dow) * 86400),
        'day' => $day,
        'week' => dtr_week_rollup($site_id, $week_start),
        'summary' => [
            'workers' => count($day),
            'set' => $set,
            'ot_total' => round($ot_total, 2)
        ],
        'has_data' => $set > 0
    ];
}

// ============================================================
// Handlers
// ============================================================

function act_site_add($ctx) {
    $name = trim((string)($ctx['post']['name'] ?? ''));
    if ($name === '') {
        return act_fail('danger', 'Site name is required.');
    }
    dbAddSite($name);
    return act_ok('success', 'Site "' . $name . '" added.', 'refresh');
}

function act_site_update($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    $name = trim((string)($ctx['post']['name'] ?? ''));
    if ($id <= 0 || $name === '') {
        return act_fail('danger', 'Site name is required.');
    }
    dbUpdateSite($id, $name);
    return act_ok('success', 'Site updated.', 'refresh');
}

function act_site_delete($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id <= 0) {
        return act_fail('danger', 'No site selected.');
    }
    $site = dbGetSite($id);
    if (!$site) {
        return act_fail('danger', 'Site not found.');
    }

    // Build a full PDF backup BEFORE deleting anything.
    $workers = dbGetSiteEmployees($id);
    $payrolls = dbGetPayrolls($id);
    $entriesById = [];
    foreach ($payrolls as $p) {
        $entriesById[(int)$p['id']] = prWithCalc(dbGetPayrollEntries((int)$p['id']));
    }
    $advances = dbFetchAll(
        "SELECT pca.id, pca.amount, pca.advance_date, pca.note,
            se.id AS site_employee_id, e.name AS worker_name
         FROM personal_cash_advances pca
         JOIN site_employees se ON se.id = pca.site_employee_id
         JOIN employees e ON e.id = se.employee_id
         WHERE se.site_id = :sid
         ORDER BY pca.advance_date DESC",
        [':sid' => $id]
    ) ?: [];
    foreach ($advances as &$a) {
        $a['balance'] = dbPersonalCaBalance((int)$a['site_employee_id']);
    }
    unset($a);

    $bytes = prPdfSiteBackup($site, $workers, $payrolls, $entriesById, $advances);
    dbDeleteSite($id);

    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $site['name'] ?? '') ?: 'site';
    return act_ok('success', 'Site deleted. Full backup downloaded as PDF.', 'pdf', [
        'pdf'      => base64_encode($bytes),
        'filename' => 'site-backup-' . $name . '-' . date('Y-m-d') . '.pdf',
        'url'      => '',
    ]);
}

function act_worker_add($ctx) {
    $site_id = (int)($ctx['site_id'] ?? 0);
    $name = trim((string)($ctx['post']['name'] ?? ''));
    $position = trim((string)($ctx['post']['position'] ?? ''));
    $rate = (float)($ctx['post']['rate'] ?? 0);
    if ($name === '') {
        return act_fail('danger', 'Employee name is required.');
    }
    $emp = dbFindEmployeeByName($name);
    if (!$emp) {
        dbInsert('employees', ['name' => $name]);
        $emp = dbFindEmployeeByName($name);
    }
    if (dbGetSiteEmployeeByEmployee($site_id, (int)$emp['id'])) {
        return act_fail('warning', $name . ' is already assigned to this site.');
    }
    dbAddSiteEmployee($site_id, (int)$emp['id'], $position, $rate);
    return act_ok('success', $name . ' added to the site.', 'refresh');
}

function act_worker_update($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    $position = trim((string)($ctx['post']['position'] ?? ''));
    $rate = (float)($ctx['post']['rate'] ?? 0);
    if ($id <= 0) {
        return act_fail('danger', 'No worker selected.');
    }
    dbUpdateSiteEmployee($id, $position, $rate);
    return act_ok('success', 'Worker updated.', 'refresh');
}

function act_worker_delete($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id > 0) {
        dbDeleteSiteEmployee($id);
        return act_ok('success', 'Worker removed (payroll entries deleted too).', 'refresh');
    }
    return act_fail('danger', 'No worker selected.');
}

function act_payroll_add($ctx) {
    $site_id = (int)($ctx['site_id'] ?? ($ctx['post']['site_id'] ?? 0));
    $week_start = trim((string)($ctx['post']['week_start'] ?? ''));
    $week_end = trim((string)($ctx['post']['week_end'] ?? ''));
    $budget = (float)($ctx['post']['budget'] ?? 0);
    $site_deduction = (float)($ctx['post']['site_deduction'] ?? 0);
    $add_expenses = (float)($ctx['post']['add_expenses'] ?? 0);

    if ($site_id <= 0) {
        return act_fail('danger', 'No site selected.');
    }
    if ($week_start === '' || $week_end === '') {
        return act_fail('danger', 'Week start and end dates are required.');
    }
    if (strtotime($week_end) < strtotime($week_start)) {
        return act_fail('danger', 'Week end must be on or after week start.');
    }
    if (dbGetPayrollByWeek($site_id, $week_start, $week_end)) {
        return act_fail('warning', 'A payroll already exists for this week.');
    }

    // Guard 1: the previous payroll week must have been saved (has entries),
    // otherwise the chain of OT/entries is incomplete.
    $prev = dbLatestPayrollForSite($site_id);
    if ($prev && strtotime($prev['week_end']) < strtotime($week_start)) {
        $prev_entries = count(dbGetPayrollEntries((int)$prev['id']));
        if ($prev_entries === 0) {
            return act_fail(
                'danger',
                'The previous payroll week (' . prDate($prev['week_start']) . ' - ' . prDate($prev['week_end'])
                . ') has no saved entries yet. Open Edit / Save Entries and save it before adding a new week.'
            );
        }
    }

    // Guard 2: a week that has begun (past or current) must have some saved
    // DTR attendance, otherwise the payroll would be based on nothing.
    if (strtotime($week_start) <= strtotime(date('Y-m-d'))) {
        $att_count = dbWeekAttendanceCount($site_id, $week_start, $week_end);
        if ($att_count === 0) {
            return act_fail(
                'danger',
                'No DTR attendance has been saved for this week yet ('
                . prDate($week_start) . ' - ' . prDate($week_end)
                . '). Enter and save attendance in the DTR before creating this payroll week.'
            );
        }
    }

    $payroll_id = dbAddPayroll($site_id, $week_start, $week_end, $budget, $site_deduction, $add_expenses);
    if (!$payroll_id) {
        return act_fail('danger', 'Could not create the payroll week.');
    }
    return act_ok(
        'success',
        'Payroll week added. Now enter the per-worker entries (incl. cash advance and personal cash advance).',
        'redirect',
        ['url' => 'payroll_form.php?payroll_id=' . (int)$payroll_id]
    );
}

function act_payroll_delete($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id <= 0) {
        return act_fail('danger', 'No payroll selected.');
    }
    $payroll = dbGetPayroll($id);
    if (!$payroll) {
        return act_fail('danger', 'Payroll not found.');
    }

    // Build a full PDF backup BEFORE deleting the week.
    $site = dbGetSite((int)$payroll['site_id']);
    $site_name = $site ? (string)$site['name'] : ('Site ' . (int)$payroll['site_id']);
    $entries = prWithCalc(dbGetPayrollEntries($id));
    $bytes = prPdfPayrollBackup($payroll, $entries, $site_name);
    dbDeletePayroll($id);

    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $site_name) ?: 'payroll';
    return act_ok('success', 'Payroll week deleted. Backup downloaded as PDF.', 'pdf', [
        'pdf'      => base64_encode($bytes),
        'filename' => 'payroll-backup-' . $name . '-' . $payroll['week_start'] . '.pdf',
        'url'      => '',
    ]);
}

function act_payroll_save($ctx) {
    $post = $ctx['post'];
    $payroll_id = (int)($ctx['payroll_id'] ?? ($post['payroll_id'] ?? 0));
    $payroll = !empty($ctx['payroll']) ? $ctx['payroll'] : dbGetPayroll($payroll_id);
    if (!$payroll) {
        return act_fail('danger', 'Payroll not found.');
    }
    $site_id = (int)$payroll['site_id'];
    $workers = dbGetSiteEmployees($site_id);
    $week_start = $payroll['week_start'];
    $week_end = $payroll['week_end'];
    $prev_start = date('Y-m-d', strtotime($week_start . ' -7 days'));
    $prev_end = date('Y-m-d', strtotime($week_end . ' -7 days'));
    $prev_att = dbWeekAttendanceByWorker($site_id, $prev_start, $prev_end);

    $entries_map = [];
    foreach (dbGetPayrollEntries($payroll_id) as $e) {
        $entries_map[(int)$e['site_employee_id']] = $e;
    }

    $changes = 0;
    $week_dates = [];
    for ($i = 0; $i < 7; $i++) {
        $week_dates[] = date('Y-m-d', strtotime($week_start . " +$i days"));
    }

    foreach ($workers as $w) {
        $k = (int)$w['id'];
        $pa = $prev_att[$k] ?? ['codes' => '.......', 'days' => 0.0, 'ot_total' => 0.0, 'ot_daily' => '0,0,0,0,0,0,0'];

        $codes = [];
        foreach ($week_dates as $idx => $date) {
            $c = strtoupper(substr(trim((string)($post['att_' . $k . '_' . $idx] ?? '')), 0, 1));
            $c = in_array($c, ['P', 'A', 'H', '.'], true) ? $c : '.';
            $codes[] = $c;
            $otd = (float)($post['otd_' . $k . '_' . $idx] ?? 0);
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

        $ca = (float)($post['ca_' . $k] ?? 0);
        $pca = (float)($post['pca_' . $k] ?? 0);
        $ded = (float)($post['ded_' . $k] ?? 0);
        $flat = (float)($post['flat_' . $k] ?? 0);

        $hasEntry = isset($entries_map[$k]);
        $isEmpty = $days == 0 && $ot == 0 && $ca == 0 && $pca == 0 && $ded == 0 && $flat == 0;

        if ($isEmpty) {
            if ($hasEntry) {
                dbDelete('payroll_entries', ['id' => $entries_map[$k]['id']]);
                $changes++;
            }
            continue;
        }
        dbSavePayrollEntry($payroll_id, $k, $days, $ot, $ca, $ded, $att, $flat, $w['position'], $w['rate'], $pca, $ot_daily);
        $changes++;
    }
    return act_ok('success', 'Saved ' . $changes . ' entry update(s) and DTR attendance.', 'refresh');
}

function act_pca_add($ctx) {
    $k = (int)($ctx['post']['se_id'] ?? 0);
    $amount = (float)($ctx['post']['amount'] ?? 0);
    $advance_date = trim((string)($ctx['post']['advance_date'] ?? ''));
    $note = trim((string)($ctx['post']['note'] ?? ''));
    if ($k > 0 && $amount > 0 && $advance_date !== '') {
        dbAddPersonalCashAdvance($k, $amount, $advance_date, $note);
        return act_ok('success', 'Personal cash advance recorded.', 'refresh');
    }
    return act_fail('danger', 'Amount and date are required.');
}

function act_pca_delete($ctx) {
    $id = (int)($ctx['post']['pca_id'] ?? 0);
    if ($id > 0) {
        dbDeletePersonalCashAdvance($id);
        return act_ok('success', 'Personal cash advance entry deleted.', 'refresh');
    }
    return act_fail('danger', 'No cash advance selected.');
}

function act_worker_transfer($ctx) {
    $k = (int)($ctx['post']['se_id'] ?? 0);
    $to_site_id = (int)($ctx['post']['to_site_id'] ?? 0);
    $days = (float)($ctx['post']['days'] ?? 0);
    $note = trim((string)($ctx['post']['note'] ?? ''));
    $payroll = !empty($ctx['payroll']) ? $ctx['payroll'] : dbGetPayroll((int)($ctx['payroll_id'] ?? 0));
    if (!$payroll) {
        return act_fail('danger', 'Payroll not found.');
    }
    if ($k <= 0 || $to_site_id <= 0 || $days <= 0) {
        return act_fail('danger', 'Target site and days are required.');
    }
    dbAddWorkerTransfer($k, $to_site_id, $days, $payroll['week_start'], $payroll['week_end'], $note);
    return act_ok('success', 'Worker transferred to the other site for ' . $days . ' day(s).', 'refresh');
}

function act_user_create($ctx) {
    $username = trim((string)($ctx['post']['username'] ?? ''));
    $email = trim((string)($ctx['post']['email'] ?? ''));
    $password = (string)($ctx['post']['password'] ?? '');
    $role = (string)($ctx['post']['role'] ?? 'finance');
    if ($username === '' || $email === '' || $password === '') {
        return act_fail('danger', 'Username, email and password are all required.');
    }
    if (dbUsernameExists($username)) {
        return act_fail('danger', 'That username is already taken.');
    }
    if (dbEmailExists($email)) {
        return act_fail('danger', 'That email is already in use.');
    }
    dbCreateUser($username, $email, $password, $role);
    return act_ok('success', 'User "' . $username . '" created with role ' . $role . '.', 'refresh');
}

function act_user_set_role($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    $role = (string)($ctx['post']['role'] ?? 'finance');
    if ($id > 0 && $id !== (int)($ctx['user_id'] ?? 0)) {
        dbUpdateUserRole($id, $role);
        return act_ok('success', 'Role updated.', 'refresh');
    }
    return act_fail('warning', 'You cannot change your own role.');
}

function act_user_delete($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id > 0 && $id !== (int)($ctx['user_id'] ?? 0)) {
        dbDeleteUser($id);
        return act_ok('success', 'User deleted.', 'refresh');
    }
    return act_fail('warning', 'You cannot delete your own account.');
}

function act_user_rename($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    $newname = trim((string)($ctx['post']['username'] ?? ''));
    if ($id <= 0 || $newname === '') {
        return act_fail('danger', 'New username is required.');
    }
    if (dbUsernameExists($newname)) {
        return act_fail('danger', 'That username is already taken.');
    }
    dbRenameUser($id, $newname);
    return act_ok('success', 'Username updated to "' . $newname . '".', 'refresh');
}

function act_user_set_password($ctx) {
    $id = (int)($ctx['post']['id'] ?? 0);
    $newpass = (string)($ctx['post']['password'] ?? '');
    if ($id <= 0 || $newpass === '') {
        return act_fail('danger', 'New password is required.');
    }
    dbUpdateUserPassword($id, $newpass);
    return act_ok('success', 'Password updated.', 'refresh');
}

function act_dtr_save($ctx) {
    $site_id = (int)($ctx['site_id'] ?? 0);
    $date = (string)($ctx['date'] ?? '');
    if ($site_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return act_fail('danger', 'Invalid site or date.');
    }
    $workers = dbGetSiteEmployees($site_id);
    $saved = 0;
    foreach ($workers as $w) {
        $k = (int)$w['id'];
        $status = trim((string)($ctx['post']['att_' . $k] ?? '.'));
        $ot = (float)($ctx['post']['otd_' . $k] ?? 0);
        $note = trim((string)($ctx['post']['note_' . $k] ?? ''));
        if (dbSaveAttendance($k, $date, $status, $ot, $note)) {
            $saved++;
        }
    }
    return act_ok(
        'success',
        'Attendance saved for ' . date('M d, Y', strtotime($date)) . '.',
        'dtr_day',
        dtr_day_payload($site_id, $date)
    );
}

function act_dtr_get($ctx) {
    $site_id = (int)($ctx['site_id'] ?? 0);
    $date = (string)($ctx['date'] ?? '');
    if ($site_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return act_fail('danger', 'Invalid site or date.');
    }
    return act_ok('success', '', 'dtr_day', dtr_day_payload($site_id, $date));
}

// ============================================================
// Dispatcher
// ============================================================

function run_action($action, $ctx) {
    $is_admin = !empty($ctx['is_admin']);

    $admin_handlers = [
        'site.add'       => 'act_site_add',
        'site.update'    => 'act_site_update',
        'site.delete'    => 'act_site_delete',
        'worker.add'     => 'act_worker_add',
        'worker.update'  => 'act_worker_update',
        'worker.delete'  => 'act_worker_delete',
        'payroll.add'    => 'act_payroll_add',
        'payroll.delete' => 'act_payroll_delete',
        'payroll.save'   => 'act_payroll_save',
        'pca.add'        => 'act_pca_add',
        'pca.delete'     => 'act_pca_delete',
        'worker.transfer'=> 'act_worker_transfer',
        'user.create'    => 'act_user_create',
        'user.set_role'  => 'act_user_set_role',
        'user.delete'    => 'act_user_delete',
        'user.rename'    => 'act_user_rename',
        'user.set_password' => 'act_user_set_password',
    ];

    $any_handlers = [
        'dtr.save_day' => 'act_dtr_save',
        'dtr.get_day'  => 'act_dtr_get',
    ];

    try {
        if (isset($admin_handlers[$action])) {
            if (!$is_admin) {
                return act_fail('warning', 'Finance users can only view. Changes not saved.');
            }
            try {
                return call_user_func($admin_handlers[$action], $ctx);
            } catch (PDOException $e) {
                if (strpos($action, 'site.') === 0) {
                    return act_fail('danger', 'That site name is already in use.');
                }
                return act_fail('danger', 'Could not save: ' . $e->getMessage());
            }
        }
        if (isset($any_handlers[$action])) {
            try {
                return call_user_func($any_handlers[$action], $ctx);
            } catch (PDOException $e) {
                return act_fail('danger', 'Could not save: ' . $e->getMessage());
            }
        }
        return act_fail('danger', 'Unknown action.');
    } catch (PDOException $e) {
        return act_fail('danger', 'Could not save: ' . $e->getMessage());
    }
}