<?php
/**
 * Minimal pure-PHP PDF generator (no external dependencies).
 * Uses the base-14 Helvetica font so nothing needs embedding.
 * Layout: US Letter portrait [0 0 612 792], 36pt side margins.
 * Provides a small cursor/table API plus ready-made backup builders for
 * payroll weeks and whole sites (used before delete to preserve the data).
 */

// Escape a string for PDF text literals (only printable ASCII passes).
function prPdfEsc($s) {
    $s = (string)$s;
    $s = preg_replace('/[^\x20-\x7E]/u', '?', $s);
    return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
}

function prPdfNum($v) {
    $v = (float)$v;
    return $v === round($v) ? (string)(int)$v : sprintf('%.2F', $v);
}

final class PrPdf
{
    const W = 612;
    const H = 792;
    const ML = 36;
    const MR = 36;
    const MT = 42;
    const MB = 34;

    private $docTitle = '';
    private $content = '';
    private $pages = [];
    private $page = 0;
    private $y = 0;

    public function __construct($docTitle = '') {
        $this->docTitle = (string)$docTitle;
        $this->newPage();
    }

    private function newPage() {
        if ($this->page > 0) {
            $this->pages[] = $this->content;
        }
        $this->page++;
        $this->content = '';
        $this->y = self::H - self::MT;
        $this->drawDocHeader();
    }

    public function pageBreak() {
        $this->newPage();
    }

    private function drawDocHeader() {
        if ($this->docTitle !== '') {
            $this->rawText(self::ML, $this->y + 2, 9, 'B', $this->docTitle);
        }
        $this->content .= "BT /F1 8 Tf " . prPdfNum(self::W - self::MR - 110) . ' ' . prPdfNum($this->y + 2) . " Td (Generated " . date('M d, Y H:i') . "  -  Page " . $this->page . ") Tj ET\n";
        $y = $this->y - 7;
        $this->content .= sprintf("%s %s %s %s re S\n", prPdfNum(self::ML), prPdfNum($y), prPdfNum(self::W - self::ML - self::MR), prPdfNum(1));
    }

    private function ensureSpace($need) {
        if ($this->y - $need < self::MB) {
            $this->newPage();
        }
    }

    private function rawText($x, $baseline, $size, $style, $str) {
        $style = strtoupper($style) === 'B' ? 'F2' : 'F1';
        $this->content .= sprintf(
            "BT /%s %s Tf %s %s Td (%s) Tj ET\n",
            $style, prPdfNum($size), prPdfNum($x), prPdfNum($baseline), prPdfEsc($str)
        );
    }

    public function box($x, $y, $w, $h) {
        $this->content .= sprintf("%s %s %s %s re S\n", prPdfNum($x), prPdfNum($y), prPdfNum($w), prPdfNum($h));
    }

    public function textAt($x, $baseline, $size, $style, $str) {
        $this->rawText($x, $baseline, $size, $style, $str);
    }

    public function getY() {
        return $this->y;
    }

    public function setY($v) {
        $this->y = $v;
    }

    public function heading($text) {
        $this->ensureSpace(28);
        $this->y -= 16;
        $this->content .= "BT /F2 12 Tf " . prPdfNum(self::ML) . ' ' . prPdfNum($this->y) . " Td (" . prPdfEsc($text) . ") Tj ET\n";
        $this->y -= 6;
        $this->content .= sprintf("%s %s %s %s re S\n", prPdfNum(self::ML), prPdfNum($this->y), prPdfNum(self::W - self::ML - self::MR), prPdfNum(1));
    }

    public function subheading($text) {
        $this->ensureSpace(24);
        $this->y -= 18;
        $this->rawText(self::ML, $this->y, 10, 'B', $text);
    }

    public function paragraph($text, $size = 8.5) {
        $text = (string)$text;
        $maxChars = (int)((self::W - self::ML - self::MR) / ($size * 0.5));
        $lines = mb_str_split($text, $maxChars);
        $this->ensureSpace(count($lines) * 11 + 4);
        foreach ($lines as $ln) {
            $this->y -= 11;
            $this->rawText(self::ML, $this->y, $size, 'N', $ln);
        }
    }

    public function meta($label, $value) {
        $this->ensureSpace(14);
        $this->y -= 13;
        $this->rawText(self::ML, $this->y, 8.5, 'B', $label . ':');
        $this->rawText(self::ML + 95, $this->y, 8.5, 'N', (string)$value);
    }

    public function spacer($pts = 8) {
        $this->ensureSpace($pts + 4);
        $this->y -= $pts;
    }

    /**
     * $headers: list of column names
     * $rows:    list of cell arrays (each a scalar)
     * $widths:  column widths in points (sum <= usable width)
     * $right:   list of column indexes right-aligned (numbers)
     */
    public function table($headers, $rows, $widths, $right = []) {
        $usable = self::W - self::ML - self::MR;
        $scale = array_sum($widths) > $usable ? $usable / array_sum($widths) : 1;
        $rowH = 13;
        $x = [];
        $acc = self::ML;
        foreach ($widths as $w) {
            $acc += $w * $scale;
            $x[] = $acc;
        }

        $drawHeader = function () use ($headers, $x, $rowH) {
            $this->ensureSpace($rowH);
            $top = $this->y;
            $bot = $top - $rowH;
            $prev = self::ML;
            foreach ($x as $cx) {
                $this->content .= sprintf("0.9 0.9 0.9 rg %s %s %s %s re f\n",
                    prPdfNum($prev), prPdfNum($bot), prPdfNum($cx - $prev), prPdfNum($rowH));
                $this->content .= sprintf("%s %s %s %s re S\n",
                    prPdfNum($prev), prPdfNum($bot), prPdfNum($cx - $prev), prPdfNum($rowH));
                $prev = $cx;
            }
            foreach ($headers as $i => $h) {
                $left = $i === 0 ? self::ML : $x[$i - 1];
                $this->rawText($left + 3, $bot + 4, 7.2, 'B', $h);
            }
            $this->y = $bot;
        };

        $drawHeader();

        foreach ($rows as $row) {
            if ($this->y - $rowH < self::MB) {
                $this->newPage();
                $drawHeader();
            }
            $top = $this->y;
            $bot = $top - $rowH;
            $prev = self::ML;
            foreach ($x as $cx) {
                $this->content .= sprintf("%s %s %s %s re S\n",
                    prPdfNum($prev), prPdfNum($bot), prPdfNum($cx - $prev), prPdfNum($rowH));
                $prev = $cx;
            }
            foreach ($row as $i => $cell) {
                $txt = (string)$cell;
                $size = 7.4;
                $left = $i === 0 ? self::ML : $x[$i - 1];
                $cellRight = $x[$i];
                $maxChars = (int)((($cellRight - $left) - 6) / ($size * 0.5));
                if (strlen($txt) > $maxChars && $maxChars > 1) {
                    $txt = substr($txt, 0, $maxChars - 1) . '..';
                }
                if (in_array($i, $right, true)) {
                    $tw = strlen($txt) * $size * 0.5;
                    $this->rawText($cellRight - 3 - $tw, $bot + 4, $size, 'N', $txt);
                } else {
                    $this->rawText($left + 3, $bot + 4, $size, 'N', $txt);
                }
            }
            $this->y = $bot;
        }
    }

    public function finish() {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $n = count($this->pages);
        if ($n === 0) {
            return "%PDF-1.4\n%%EOF";
        }

        $out = "%PDF-1.4\n";
        $offsets = [];
        $put = function ($body) use (&$out, &$offsets) {
            $offsets[] = strlen($out);
            $out .= $body . "\n";
        };

        $put("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj");
        $kids = [];
        for ($i = 1; $i <= $n; $i++) {
            $kids[] = (3 + $i) . ' 0 R';
        }
        $put("2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . $n . " >>\nendobj");
        $put("3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj");
        $put("4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj");

        for ($i = 1; $i <= $n; $i++) {
            $contentObj = 4 + $n + $i;
            $put((4 + $i) . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . $contentObj . " 0 R >>\nendobj");
        }
        for ($i = 1; $i <= $n; $i++) {
            $body = $this->pages[$i - 1];
            $put((4 + $n + $i) . " 0 obj\n<< /Length " . strlen($body) . " >>\nstream\n" . $body . "\nendstream\nendobj");
        }

        $xref = strlen($out);
        $out .= "xref\n0 " . (count($offsets) + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= "trailer\n<< /Size " . (count($offsets) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $out;
    }
}

/**
 * A table row array for the payroll (worker) backup: computed money cells.
 */
function prPdfEntryRows($entries) {
    $rows = [];
    foreach ($entries as $e) {
        $rows[] = [
            (string)$e['name'],
            (string)($e['position'] ?? ''),
            number_format((float)$e['rate'], 2),
            number_format((float)$e['days_worked'], 1),
            number_format((float)$e['ot_hours'], 1),
            number_format((float)$e['basic'], 2),
            number_format((float)$e['ot_pay'], 2),
            number_format((float)$e['gross'], 2),
            number_format((float)$e['personal_cash_advance'], 2),
            number_format((float)$e['cash_advance'], 2),
            number_format((float)$e['deduction'], 2),
            number_format((float)$e['flat_pay'], 2),
            number_format((float)$e['net'], 2),
        ];
    }
    return $rows;
}

const PRPDF_ENTRY_WIDTHS = [100, 52, 28, 20, 20, 36, 30, 38, 30, 28, 22, 22, 40];
const PRPDF_ENTRY_HEADERS = ['Worker', 'Position', 'Rate', 'Days', 'OT', 'Basic', 'OT Pay', 'Gross', 'Per.CA', 'Cash Adv', 'Ded', 'Flat', 'Net'];
const PRPDF_ENTRY_RIGHT = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

/**
 * Full backup of one payroll week (entries with money math + totals).
 */
function prPdfPayrollBackup($payroll, $entries, $site_name) {
    $pdf = new PrPdf('PAYROLL BACKUP');
    $pdf->heading('PAYROLL BACKUP');
    $pdf->meta('Site', $site_name);
    $pdf->meta('Week', prDate($payroll['week_start']) . ' - ' . prDate($payroll['week_end']));
    $pdf->meta('Payroll ID', $payroll['id']);
    $pdf->meta('Entries', count($entries));

    $totals = prPayrollTotals($entries, $payroll);
    $pdf->meta('Budget (Cash Adv)', prMoney($totals['budget']));
    $pdf->meta('Site Deduction', prMoney($totals['site_deduction']));
    $pdf->meta('Add. Expenses', prMoney($totals['add_expenses']));
    $pdf->meta('TOTAL PAYROLL', prMoney($totals['payroll_total']));
    $pdf->meta('TOTAL TOTAL', prMoney($totals['net']));

    $pdf->spacer(6);
    $pdf->subheading('Entries');
    $pdf->table(PRPDF_ENTRY_HEADERS, prPdfEntryRows($entries), PRPDF_ENTRY_WIDTHS, PRPDF_ENTRY_RIGHT);

    $daysTot = 0; $otTot = 0; $basicTot = 0; $otPayTot = 0; $grossTot = 0;
    $caTot = 0; $pcaTot = 0; $dedTot = 0; $flatTot = 0; $netTot = 0;
    foreach ($entries as $e) {
        $daysTot   += (float)$e['days_worked'];
        $otTot     += (float)$e['ot_hours'];
        $basicTot  += (float)$e['basic'];
        $otPayTot  += (float)$e['ot_pay'];
        $grossTot  += (float)$e['gross'];
        $caTot     += (float)$e['cash_advance'];
        $pcaTot    += (float)$e['personal_cash_advance'];
        $dedTot    += (float)$e['deduction'];
        $flatTot   += (float)$e['flat_pay'];
        $netTot    += (float)$e['net'];
    }
    $footerRow = [
        'TOTAL (' . count($entries) . ')', '', '',
        number_format($daysTot, 1), number_format($otTot, 1),
        number_format($basicTot, 2), number_format($otPayTot, 2), number_format($grossTot, 2),
        number_format($pcaTot, 2), number_format($caTot, 2),
        number_format($dedTot, 2), number_format($flatTot, 2),
        number_format($netTot, 2),
    ];
    $pdf->table([], [$footerRow], PRPDF_ENTRY_WIDTHS, PRPDF_ENTRY_RIGHT);
    return $pdf->finish();
}

/**
 * Full backup of a site: workers list, every payroll week with entries, plus
 * personal cash advances given to the site's workers.
 */
function prPdfSiteBackup($site, $workers, $payrolls, $payrollEntriesById, $advances) {
    $sep = str_repeat('-', 90);
    $pdf = new PrPdf('SITE BACKUP');
    $pdf->heading('SITE BACKUP');
    $pdf->meta('Site', $site['name']);
    $pdf->meta('Site ID', $site['id']);
    $pdf->meta('Workers', count($workers));
    $pdf->meta('Payroll Weeks', count($payrolls));

    $pdf->spacer(6);
    $pdf->subheading('Workers (' . count($workers) . ')');
    $wRows = [];
    foreach ($workers as $w) {
        $wRows[] = [(string)$w['name'], (string)$w['position'], number_format((float)$w['rate'], 2)];
    }
    $pdf->table(['Worker', 'Position', 'Rate'], $wRows, [260, 150, 70], [2]);
    $pdf->paragraph($sep);

    if ($advances) {
        $pdf->subheading('Personal Cash Advances (' . count($advances) . ')');
        $aRows = [];
        foreach ($advances as $a) {
            $aRows[] = [
                prDate($a['advance_date']),
                (string)$a['worker_name'],
                (string)$a['note'],
                number_format((float)$a['amount'], 2),
                number_format((float)$a['balance'], 2),
            ];
        }
        $pdf->table(['Date', 'Worker', 'Note', 'Given', 'Balance'], $aRows, [60, 130, 190, 60, 60], [3, 4]);
        $pdf->paragraph($sep);
    }

    foreach ($payrolls as $p) {
        $pdf->pageBreak();
        $entries = $payrollEntriesById[(int)$p['id']] ?? [];
        $totals = $entries ? prPayrollTotals($entries, $p) : null;
        $pdf->subheading('Week: ' . prDate($p['week_start']) . ' - ' . prDate($p['week_end']) . ' (' . count($entries) . ' entries)');
        $pdf->meta('Budget', prMoney($p['budget']));
        $pdf->meta('Site Deduction', prMoney($p['site_deduction']));
        $pdf->meta('Add. Expenses', prMoney($p['add_expenses']));
        if ($totals) {
            $pdf->meta('TOTAL PAYROLL', prMoney($totals['payroll_total']));
            $pdf->meta('TOTAL TOTAL', prMoney($totals['net']));
        }
        $pdf->spacer(2);
        if ($entries) {
            $pdf->table(PRPDF_ENTRY_HEADERS, prPdfEntryRows($entries), PRPDF_ENTRY_WIDTHS, PRPDF_ENTRY_RIGHT);
        } else {
            $pdf->paragraph('(no entries)');
        }
        $pdf->paragraph($sep);
        $pdf->spacer(4);
    }
    return $pdf->finish();
}

/**
 * Draw one compact payslip stub (employee details only) inside a box.
 * 10 of these fit on one portrait bond page.
 */
function prPdfDrawPayslipStub($pdf, $e, $x, $top, $w, $h, $site_name, $week, $prevEnd) {
    $pad = 5;
    $x1 = $x + $pad;
    $size = 6.2;
    $small = 5.3;
    $pdf->box($x, $top - $h, $w, $h);

    // Header: PAYSLIP (left) + site (right)
    $pdf->textAt($x1, $top - 9, 7, 'B', 'PAYSLIP');
    $siteStr = (string)$site_name;
    $pdf->textAt($x + $w - $pad - strlen($siteStr) * 6 * 0.5, $top - 9, 6, 'N', $siteStr);

    // Worker / rate / days / OT
    $pdf->textAt($x1, $top - 18, $size, 'N',
        'Worker: ' . (string)$e['name']
        . '   Rate/Day: ' . prMoney($e['rate'])
        . '   Days: ' . number_format((float)$e['days_worked'], 1)
        . '   OT hrs (from Sat ' . $prevEnd . '): ' . number_format((float)$e['ot_hours'], 1));

    // Earnings
    $pdf->textAt($x1, $top - 27.5, $size, 'N',
        'Basic: ' . prMoney($e['basic'])
        . '   OT Pay: ' . prMoney($e['ot_pay'])
        . '   Flat: ' . prMoney($e['flat_pay'])
        . '   GROSS: ' . prMoney($e['gross']));

    // Deductions
    $pdf->textAt($x1, $top - 37, $size, 'N',
        'Per. Cash Adv: ' . prMoney($e['personal_cash_advance'])
        . '   Cash Adv: ' . prMoney($e['cash_advance'])
        . '   Deduction: ' . prMoney($e['deduction']));

    // Attendance strip (this payroll week) + prev-week OT strip (Saturday = 0)
    $codes = prNormAtt($e['attendance'] ?? '');
    $codesStr = 'Att (S-S):';
    foreach (str_split($codes) as $c) {
        $codesStr .= ' ' . $c;
    }
    $codesStr .= '   * Sat OT recorded this wk pays next wk';
    $pdf->textAt($x1, $top - 45.5, $small, 'N', $codesStr);

    $otd = prOtDailyArray($e['ot_daily'] ?? '');
    $otd[6] = 0.0;
    $otStr = 'OT prev (S-S):';
    foreach ($otd as $v) {
        $otStr .= ' ' . rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
    }
    $pdf->textAt($x1, $top - 53.5, $small, 'N', $otStr);

    // Week + NET PAY
    $weekStr = (string)$week;
    $pdf->textAt($x + $w - $pad - strlen($weekStr) * 6 * 0.5, $top - 62, 6, 'N', $weekStr);
    $netStr = 'NET PAY: ' . prMoney($e['net']);
    $pdf->textAt($x + $w - $pad - strlen($netStr) * 7.5 * 0.5, $top - 62, 7.5, 'B', $netStr);
}

/**
 * Payslips for one or more workers of a payroll week (employee details only).
 * Layout: 10 compact stubs per portrait bond page, each worker in a box.
 */
function prPdfPaySlips($payroll, $entries, $site_name) {
    $pdf = new PrPdf('PAYSLIPS');
    $pdf->heading('PAYSLIPS');
    $pdf->paragraph(prDate($payroll['week_start']) . ' - ' . prDate($payroll['week_end'])
        . '   |   ' . (string)$site_name . '   |   ' . count($entries) . ' worker(s)', 8.5);
    $pdf->spacer(4);

    $per = 10;
    $stubH = 68;
    $gap = 4;
    $x = PrPdf::ML;
    $w = PrPdf::W - PrPdf::ML - PrPdf::MR;
    $week = prDate($payroll['week_start']) . ' - ' . prDate($payroll['week_end']);
    $prevEnd = prDate(date('Y-m-d', strtotime($payroll['week_start'] . ' -1 day')));

    $i = 0;
    foreach ($entries as $e) {
        if ($i > 0 && $i % $per === 0) {
            $pdf->pageBreak();
        }
        if ($pdf->getY() - $stubH < 34) {
            $pdf->pageBreak();
        }
        $top = $pdf->getY();
        prPdfDrawPayslipStub($pdf, $e, $x, $top, $w, $stubH, $site_name, $week, $prevEnd);
        $pdf->setY($top - $stubH - $gap);
        $i++;
    }

    return $pdf->finish();
}

/**
 * Single worker payslip (same compact stub format).
 */
function prPdfPaySlip($payroll, $entry, $site_name) {
    return prPdfPaySlips($payroll, [$entry], $site_name);
}