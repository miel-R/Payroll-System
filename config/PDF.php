<?php
// E:\PAYROLL\config\PDF.php
// PDF generation via dompdf (HTML -> PDF). Every PDF the app produces (payslips
// and delete-backups) is built from the SAME HTML tables the screens show, so
// print and PDF always match and the output renders in every viewer.

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Render an HTML document to PDF bytes with dompdf.
 * $paper: named size (e.g. 'letter') or array [x, y, w, h] in points.
 */
function prPdfFromHtml($html, $paper = 'letter') {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('tempDir', sys_get_temp_dir());
    $options->set('isFontSubsettingEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $root = realpath(__DIR__ . '/..');
    if ($root !== false) {
        $options->set('chroot', [$root]);
    }
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * The payslip stub as a self-contained <table class="payslip-stub">.
 * Shared by the on-screen/print view AND the PDF download so they stay in sync.
 * $wk_att: dbWeekAttendanceByWorker() rollup for THIS week (per-day OT shown on
 * the S M T W T F S grid, Saturday always 0). Lag OT = last Saturday's OT,
 * pulled from the entry's ot_daily snapshot (previous week's DTR).
 */
function prPayslipStubHtml($se, $site_name, $week_label, $wk_att) {
    $e = function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };
    $fmtOt = function ($v) {
        return rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
    };

    $codes = str_split(prNormAtt($se['attendance'] ?? ''));
    $wk = $wk_att[(int)$se['site_employee_id']] ?? null;
    $otd = $wk ? prOtDailyArray($wk['ot_daily']) : [0, 0, 0, 0, 0, 0, 0];
    $otd[6] = 0.0;
    $lag_ot = prOtDailyArray($se['ot_daily'] ?? '')[6];
    $day_labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

    $html = '<table class="payslip-stub">';
    $html .= '<tr><td class="ps-top"><table class="ps-top-grid"><tr>'
        . '<td class="ps-title">PAYSLIP</td>'
        . '<td class="ps-site"><strong>Site: ' . $e($site_name) . '</strong></td>'
        . '</tr></table></td></tr>';
    $html .= '<tr><td class="ps-week">Payroll Week: ' . $e($week_label) . '</td></tr>';
    $html .= '<tr><td class="ps-worker"><span class="ps-lbl">Worker:</span> <strong>'
        . $e($se['name']) . '</strong></td></tr>';
    $html .= '<tr><td class="ps-meta"><table class="ps-meta-grid"><tr>'
        . '<td><span class="ps-lbl">Rate/Day:</span> ' . prMoney($se['rate']) . '</td>'
        . '<td><span class="ps-lbl">Days:</span> ' . number_format((float)$se['days_worked'], 1) . '</td>'
        . '<td><span class="ps-lbl">Lag OT:</span> ' . $fmtOt($lag_ot) . '</td>'
        . '</tr></table></td></tr>';
    $html .= '<tr><td class="ps-legend">P present / A absent / H half-day</td></tr>';
    $html .= '<tr><td class="ps-att-wrap"><table class="ps-att">';
    $html .= '<tr class="ps-att-head">';
    foreach ($day_labels as $d) {
        $html .= '<th>' . $d . '</th>';
    }
    $html .= '</tr><tr class="ps-att-code">';
    foreach ($codes as $c) {
        $html .= '<td>' . $e($c) . '</td>';
    }
    $html .= '</tr><tr class="ps-att-ot">';
    foreach ($otd as $v) {
        $html .= '<td>' . $fmtOt($v) . '</td>';
    }
    $html .= '</tr></table></td></tr>';
    $html .= '<tr><td class="ps-money"><table class="ps-money-grid"><tr>'
        . '<td><span class="ps-lbl">Basic:</span> ' . prMoney($se['basic']) . '</td>'
        . '<td><span class="ps-lbl">OT Pay:</span> ' . prMoney($se['ot_pay']) . '</td>'
        . '<td><span class="ps-lbl">Gross:</span> <strong>' . prMoney($se['gross']) . '</strong></td>'
        . '</tr></table></td></tr>';
    $html .= '<tr><td class="ps-deduct"><table class="ps-deduct-grid">'
        . '<tr><td class="ps-deduct-label" colspan="2">Deduction</td></tr>'
        . '<tr>'
        . '<td><span class="ps-lbl">Cash Adv:</span> ' . prMoney($se['cash_advance']) . '</td>'
        . '<td><span class="ps-lbl">Per. CA:</span> ' . prMoney($se['personal_cash_advance']) . '</td>'
        . '</tr>'
        . '<tr><td class="ps-total" colspan="2">Total Balance: ' . prMoney($se['net']) . '</td></tr>'
        . '</table></td></tr>';
    $html .= '</table>';
    return $html;
}

function prPayslipCss() {
    return '
@page { margin: 8mm; }
body { font-family: DejaVu Sans, sans-serif; color:#000; margin:0; }
.pagedoc h3 { font-size:11pt; margin:0 0 2pt 0; }
.pagedoc p { font-size:7.5pt; margin:0 0 4pt 0; }
table.sheet { width:100%; border-collapse:collapse; }
table.sheet.pagebreak { page-break-after: always; }
table.sheet td.stubcell { width:50%; vertical-align:top; padding:1.5mm; }

table.payslip-stub { width:100%; border-collapse:collapse; border:1pt solid #000; }
table.payslip-stub > tr > td { border-bottom:0.5pt solid #000; padding:1pt 2pt; }
table.payslip-stub > tr:last-child > td { border-bottom:none; }

table.ps-top-grid { width:100%; border-collapse:collapse; }
table.ps-top-grid td { font-size:8pt; }
table.ps-top-grid .ps-title { text-align:left; font-weight:bold; letter-spacing:0.05em; }
table.ps-top-grid .ps-site { text-align:right; }

.ps-week { text-align:center; font-weight:bold; font-size:6.5pt; background:#eee; }
.ps-worker { font-size:7pt; }
.ps-legend { text-align:center; font-size:5.8pt; color:#333; background:#f7f7f7; }

table.ps-meta-grid { width:100%; border-collapse:collapse; }
table.ps-meta-grid td { font-size:6.5pt; padding:0 2pt; }
table.ps-meta-grid td + td { border-left:0.5pt solid #000; }

table.ps-att { width:100%; border-collapse:collapse; }
table.ps-att th, table.ps-att td { border:0.5pt solid #000; padding:0.5pt 1pt; text-align:center; }
table.ps-att th { background:#eee; font-size:6pt; }
table.ps-att td { font-size:7pt; }
table.ps-att tr.ps-att-code td { font-weight:bold; }
table.ps-att tr.ps-att-ot td { font-size:6pt; }

table.ps-money-grid { width:100%; border-collapse:collapse; }
table.ps-money-grid td { border:0.5pt solid #000; padding:1pt 2pt; font-size:6.8pt; }
table.ps-money-grid td + td { border-left:0.5pt solid #000; }

table.ps-deduct-grid { width:100%; border-collapse:collapse; }
table.ps-deduct-grid td { border:0.5pt solid #000; padding:1pt 2pt; font-size:6.8pt; }
table.ps-deduct-grid td + td { border-left:0.5pt solid #000; }
table.ps-deduct-grid td.ps-deduct-label { text-align:center; font-weight:bold; font-size:6.5pt; background:#eee; letter-spacing:0.05em; }
table.ps-deduct-grid td.ps-total { text-align:right; font-weight:bold; font-size:8pt; background:#eee; }

table.payslip-stub .ps-lbl { color:#333; font-weight:normal; }
';
}

/**
 * Full payslip document: 10 stubs per bond page (2 columns x 5 rows), matching
 * the on-screen/print layout. Paper = 8.5in x 13in (612 x 936 pt).
 */
function prPayslipHtmlDoc($entries, $site_name, $week_label, $wk_att) {
    $sheets = array_chunk($entries, 10);
    $n = count($sheets);
    $html = '<html><head><meta charset="utf-8"><style>' . prPayslipCss() . '</style></head><body>';
    $html .= '<div class="pagedoc"><h3>PAYSLIPS</h3><p>'
        . htmlspecialchars($week_label, ENT_QUOTES, 'UTF-8')
        . ' &nbsp;|&nbsp; ' . htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8')
        . ' &nbsp;|&nbsp; ' . count($entries) . ' worker(s) &middot; 10 per page (2 columns of 5)</p>';
    foreach ($sheets as $i => $sheet) {
        $pb = ($i < $n - 1) ? ' pagebreak' : '';
        $cells = [];
        foreach ($sheet as $se) {
            $cells[] = '<td class="stubcell">' . prPayslipStubHtml($se, $site_name, $week_label, $wk_att) . '</td>';
        }
        $rows = (int)ceil(count($cells) / 2);
        $html .= '<table class="sheet' . $pb . '">';
        for ($r = 0; $r < $rows; $r++) {
            $html .= '<tr>'
                . ($cells[$r * 2] ?? '<td class="stubcell"></td>')
                . ($cells[$r * 2 + 1] ?? '<td class="stubcell"></td>')
                . '</tr>';
        }
        $html .= '</table>';
    }
    $html .= '</div></body></html>';
    return $html;
}

/**
 * Payslips for one or more workers of a payroll week (employee details only).
 * 10 payslips per bond page (2 columns x 5 rows), each its own aligned table.
 */
function prPdfPaySlips($payroll, $entries, $site_name) {
    $wk_att = [];
    if (function_exists('dbWeekAttendanceByWorker')) {
        $wk_att = dbWeekAttendanceByWorker((int)$payroll['site_id'], $payroll['week_start'], $payroll['week_end']);
    }
    $week_label = prDate($payroll['week_start']) . ' - ' . prDate($payroll['week_end']);
    $html = prPayslipHtmlDoc($entries, (string)$site_name, $week_label, $wk_att);
    return prPdfFromHtml($html, [0, 0, 612, 936]);
}

/**
 * Single worker payslip (same compact stub format).
 */
function prPdfPaySlip($payroll, $entry, $site_name) {
    return prPdfPaySlips($payroll, [$entry], $site_name);
}

// ============================================================
// BACKUP PDFs (built before a site/payroll week is deleted)
// ============================================================

function prBackupCss() {
    return '
@page { margin: 10mm; }
body { font-family: DejaVu Sans, sans-serif; color:#000; font-size:8pt; margin:0; }
.doc h3 { font-size:13pt; margin:0 0 4pt 0; }
.doc p { margin:0 0 2pt 0; font-size:8pt; }
table.bk { width:100%; border-collapse:collapse; margin-top:5pt; }
table.bk td, table.bk th { border:0.5pt solid #000; padding:1.5pt 3pt; font-size:6.5pt; line-height:1.25; }
table.bk th { background:#eee; text-align:left; }
table.bk td.num, table.bk th.num { text-align:right; }
div.week { page-break-before: always; }
.week h4 { font-size:9pt; margin:4pt 0 2pt 0; }
';
}

function prBackupMetaRows($pairs) {
    $html = '';
    foreach ($pairs as $label => $value) {
        $html .= '<p><strong>' . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8')
            . ':</strong> ' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    return $html;
}

function prEntriesTable($entries) {
    $headers = ['Worker', 'Position', 'Rate', 'Days', 'OT', 'Basic', 'OT Pay', 'Gross', 'Per.CA', 'Cash Adv', 'Ded', 'Flat', 'Net'];
    $html = '<table class="bk"><tr>';
    foreach ($headers as $h) {
        $html .= '<th' . (in_array($h, ['Rate', 'Days', 'OT', 'Basic', 'OT Pay', 'Gross', 'Per.CA', 'Cash Adv', 'Ded', 'Flat', 'Net'], true) ? ' class="num"' : '') . '>' . $h . '</th>';
    }
    $html .= '</tr>';

    $sum = array_fill(0, 13, 0.0);
    foreach ($entries as $e) {
        $vals = [
            (string)$e['name'], (string)($e['position'] ?? ''),
            (float)$e['rate'], (float)$e['days_worked'], (float)$e['ot_hours'],
            (float)$e['basic'], (float)$e['ot_pay'], (float)$e['gross'],
            (float)$e['personal_cash_advance'], (float)$e['cash_advance'],
            (float)$e['deduction'], (float)$e['flat_pay'], (float)$e['net'],
        ];
        $html .= '<tr>';
        foreach ($vals as $i => $v) {
            $cls = $i >= 2 ? ' class="num"' : '';
            if ($i === 0 || $i === 1) {
                $html .= '<td>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td>';
            } else {
                $num = $i === 3 ? number_format($v, 1) : number_format($v, 2);
                $html .= '<td class="num">' . $num . '</td>';
                if ($i >= 2) {
                    $sum[$i] += $v;
                }
            }
        }
        $html .= '</tr>';
    }

    $html .= '<tr><td colspan="2"><strong>TOTAL (' . count($entries) . ')</strong></td>';
    foreach (array_slice($sum, 2, null, true) as $i => $v) {
        $num = $i === 3 ? number_format($v, 1) : number_format($v, 2);
        $html .= '<td class="num"><strong>' . $num . '</strong></td>';
    }
    $html .= '</tr></table>';
    return $html;
}

/**
 * Full backup of one payroll week (entries with money math + totals).
 */
function prPdfPayrollBackup($payroll, $entries, $site_name) {
    $totals = prPayrollTotals($entries, $payroll);
    $html = '<html><head><meta charset="utf-8"><style>' . prBackupCss() . '</style></head><body>';
    $html .= '<div class="doc"><h3>PAYROLL BACKUP</h3>';
    $html .= prBackupMetaRows([
        'Site' => (string)$site_name,
        'Week' => prDate($payroll['week_start']) . ' - ' . prDate($payroll['week_end']),
        'Payroll ID' => $payroll['id'],
        'Entries' => count($entries),
        'Budget (Cash Adv)' => prMoney($totals['budget']),
        'Site Deduction' => prMoney($totals['site_deduction']),
        'Add. Expenses' => prMoney($totals['add_expenses']),
        'TOTAL PAYROLL' => prMoney($totals['payroll_total']),
        'TOTAL TOTAL' => prMoney($totals['net']),
    ]);
    $html .= prEntriesTable($entries);
    $html .= '</div></body></html>';
    return prPdfFromHtml($html, 'letter');
}

/**
 * Full backup of a site: workers list, every payroll week with entries, plus
 * personal cash advances given to the site's workers.
 */
function prPdfSiteBackup($site, $workers, $payrolls, $payrollEntriesById, $advances) {
    $html = '<html><head><meta charset="utf-8"><style>' . prBackupCss() . '</style></head><body>';
    $html .= '<div class="doc"><h3>SITE BACKUP</h3>';
    $html .= prBackupMetaRows([
        'Site' => (string)($site['name'] ?? ''),
        'Site ID' => $site['id'],
        'Workers' => count($workers),
        'Payroll Weeks' => count($payrolls),
    ]);

    $html .= '<h4>Workers (' . count($workers) . ')</h4>';
    $html .= '<table class="bk"><tr><th>Worker</th><th>Position</th><th class="num">Rate</th></tr>';
    foreach ($workers as $w) {
        $html .= '<tr><td>' . htmlspecialchars((string)$w['name'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)$w['position'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="num">' . number_format((float)$w['rate'], 2) . '</td></tr>';
    }
    $html .= '</table>';

    if ($advances) {
        $html .= '<h4>Personal Cash Advances (' . count($advances) . ')</h4>';
        $html .= '<table class="bk"><tr><th>Date</th><th>Worker</th><th>Note</th><th class="num">Given</th><th class="num">Balance</th></tr>';
        foreach ($advances as $a) {
            $html .= '<tr><td>' . prDate($a['advance_date']) . '</td>'
                . '<td>' . htmlspecialchars((string)($a['worker_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string)($a['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="num">' . number_format((float)$a['amount'], 2) . '</td>'
                . '<td class="num">' . number_format((float)($a['balance'] ?? 0), 2) . '</td></tr>';
        }
        $html .= '</table>';
    }

    foreach ($payrolls as $p) {
        $entries = $payrollEntriesById[(int)$p['id']] ?? [];
        $totals = $entries ? prPayrollTotals($entries, $p) : null;
        $html .= '<div class="week">';
        $html .= '<h4>Week: ' . htmlspecialchars(prDate($p['week_start']) . ' - ' . prDate($p['week_end']), ENT_QUOTES, 'UTF-8')
            . ' (' . count($entries) . ' entries)</h4>';
        $pairs = [
            'Budget' => prMoney($p['budget']),
            'Site Deduction' => prMoney($p['site_deduction']),
            'Add. Expenses' => prMoney($p['add_expenses']),
        ];
        if ($totals) {
            $pairs['TOTAL PAYROLL'] = prMoney($totals['payroll_total']);
            $pairs['TOTAL TOTAL'] = prMoney($totals['net']);
        }
        $html .= prBackupMetaRows($pairs);
        if ($entries) {
            $html .= prEntriesTable($entries);
        } else {
            $html .= '<p>(no entries)</p>';
        }
        $html .= '</div>';
    }

    $html .= '</div></body></html>';
    return prPdfFromHtml($html, 'letter');
}
