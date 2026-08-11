<?php
// E:\PAYROLL\import_seed.php
// One-time / dev tool: creates the payroll tables and loads the verified
// weekly payroll data from data/payroll_seed.json (parsed from the source
// xlsx by tools/xlsx_to_json.py). Requires an authenticated session.
// NOTE: delete this file from the server after importing.

$page_title = 'Import Seed Data';
$active_page = '';
require_once __DIR__ . '/inc/header.php';

$json_path = __DIR__ . '/data/payroll_seed.json';
$messages = [];

// ------------------------------------------------------------
// Schema
// ------------------------------------------------------------
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
        UNIQUE KEY uniq_entry (payroll_id, site_employee_id)
    ) ENGINE=InnoDB",
];

function prCreateSchema($schema) {
    dbconnect();
    global $pdo;
    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }
}

// ------------------------------------------------------------
// Import
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['run'] ?? '') === '1') {
    try {
        $json = json_decode((string)file_get_contents($json_path), true);
        if ($json === null || !isset($json['sites'])) {
            $messages[] = ['danger', 'Could not read or parse ' . basename($json_path) . '.'];
        } else {
            prCreateSchema($schema);
            dbconnect();
            global $pdo;
            $pdo->beginTransaction();

            dbExecute('DELETE FROM payroll_entries');
            dbExecute('DELETE FROM payrolls');
            dbExecute('DELETE FROM site_employees');
            dbExecute('DELETE FROM employees');
            dbExecute('DELETE FROM sites');

            $counts = ['sites' => 0, 'employees' => 0, 'site_employees' => 0, 'payrolls' => 0, 'entries' => 0];

            foreach ($json['sites'] as $site) {
                $site_id = (int)dbInsert('sites', ['name' => $site['name']]);
                $counts['sites']++;

                foreach ($site['payrolls'] as $p) {
                    $payroll_id = (int)dbInsert('payrolls', [
                        'site_id' => $site_id,
                        'week_start' => $p['week_start'],
                        'week_end' => $p['week_end'],
                        'budget' => $p['budget'],
                        'site_deduction' => $p['site_deduction'],
                        'add_expenses' => $p['add_expenses'],
                    ]);
                    $counts['payrolls']++;

                    foreach ($p['entries'] as $e) {
                        if (!dbFindEmployeeByName($e['name'])) {
                            dbInsert('employees', ['name' => $e['name']]);
                            $counts['employees']++;
                        }
                        $employee_id = (int)dbFindEmployeeByName($e['name'])['id'];

                        $se = dbGetSiteEmployeeByEmployee($site_id, $employee_id);
                        if ($se) {
                            dbUpdateSiteEmployee((int)$se['id'], $e['position'], $e['rate']);
                            $site_emp_id = (int)$se['id'];
                        } else {
                            $site_emp_id = (int)dbAddSiteEmployee($site_id, $employee_id, $e['position'], $e['rate']);
                            $counts['site_employees']++;
                        }

                        dbSavePayrollEntry(
                            $payroll_id, $site_emp_id,
                            $e['days'], $e['ot_hours'], $e['cash_advance'], $e['deduction'],
                            $e['attendance'], $e['flat_pay'], $e['position'], $e['rate'],
                            $e['personal_cash_advance'] ?? 0
                        );
                        $counts['entries']++;
                    }
                }
            }

            $pdo->commit();

            $messages[] = ['success', 'Seed data imported successfully. ' .
                sprintf('%d sites, %d employees, %d site assignments, %d payrolls, %d entries.',
                    $counts['sites'], $counts['employees'], $counts['site_employees'],
                    $counts['payrolls'], $counts['entries'])];
        }
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        $messages[] = ['danger', 'Import failed: ' . htmlspecialchars($e->getMessage())];
    }
}

$stats = [];
if (dbTableExists('sites')) {
    $stats = [
        'sites' => (int)dbFetchColumn('SELECT COUNT(*) FROM sites'),
        'employees' => (int)dbFetchColumn('SELECT COUNT(*) FROM employees'),
        'site_employees' => (int)dbFetchColumn('SELECT COUNT(*) FROM site_employees'),
        'payrolls' => (int)dbFetchColumn('SELECT COUNT(*) FROM payrolls'),
        'entries' => (int)dbFetchColumn('SELECT COUNT(*) FROM payroll_entries'),
    ];
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="content-card">
            <h4><i class="bi bi-upload"></i> Import Seed Data</h4>

            <?php foreach ($messages as $m): ?>
                <div class="alert alert-<?php echo $m[0]; ?> alert-dismissible fade show" role="alert">
                    <?php echo $m[1]; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>

            <p>
                This page creates the payroll tables and loads the weekly payroll data
                that was parsed from the source spreadsheet
                (<code>data/payroll_seed.json</code>).
            </p>

            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Sites</th>
                        <th>Employees</th>
                        <th>Site Assignments</th>
                        <th>Payrolls</th>
                        <th>Entries</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo $stats['sites'] ?? '-'; ?></td>
                        <td><?php echo $stats['employees'] ?? '-'; ?></td>
                        <td><?php echo $stats['site_employees'] ?? '-'; ?></td>
                        <td><?php echo $stats['payrolls'] ?? '-'; ?></td>
                        <td><?php echo $stats['entries'] ?? '-'; ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Running the import <strong>wipes</strong> existing payroll, worker, and site
                data and replaces it with the seed file. Only do this once after setting up.
            </div>

            <form method="POST" action="" onsubmit="return confirm('Wipe current payroll data and import seed?');">
                <input type="hidden" name="run" value="1">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-cloud-download"></i> Import Seed Data
                </button>
                <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
