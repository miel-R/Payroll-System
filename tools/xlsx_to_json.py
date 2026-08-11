#!/usr/bin/env python3
"""
One-time dev utility: parse GENERAL PAYROLL.xlsx and emit data/payroll_seed.json.

Uses only the Python standard library (zipfile + ElementTree). The xlsx is a
zip of XML files: sharedStrings.xml holds cell text, worksheet XML holds cells.

Run (from repo root):
    python tools/xlsx_to_json.py "C:/path/to/GENERAL PAYROLL.xlsx"

Then load the JSON into the database via the import_seed.php page.
"""
import datetime
import json
import re
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'

MONTHS = {
    'JANUARY': 1, 'FEBRUARY': 2, 'MARCH': 3, 'APRIL': 4, 'MAY': 5, 'JUNE': 6,
    'JULY': 7, 'AUGUST': 8, 'SEPTEMBER': 9, 'OCTOBER': 10, 'NOVEMBER': 11, 'DECEMBER': 12,
}


def load_sheets(xlsx_path):
    z = zipfile.ZipFile(xlsx_path)
    wb = ET.fromstring(z.read('xl/workbook.xml'))
    rels = ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))
    relmap = {r.get('Id'): r.get('Target') for r in rels}

    ss = ET.fromstring(z.read('xl/sharedStrings.xml'))
    strings = []
    for si in ss.findall('{%s}si' % NS):
        strings.append(''.join(t.text or '' for t in si.iter('{%s}t' % NS)))

    sheets = []
    for s in wb.findall('.//{%s}sheet' % NS):
        name = s.get('name')
        target = relmap[s.get('{%s}id' % REL_NS)].lstrip('/')
        if not target.startswith('xl/'):
            target = 'xl/' + target
        root = ET.fromstring(z.read(target))
        sd = root.find('.//{%s}sheetData' % NS)
        grid = {}
        maxc = 0
        maxr = 0
        for row in sd.findall('{%s}row' % NS):
            r = int(row.get('r'))
            for c in row.findall('{%s}c' % NS):
                m = re.match(r'([A-Z]+)(\d+)', c.get('r'))
                col = 0
                for ch in m.group(1):
                    col = col * 26 + (ord(ch) - 64)
                col -= 1
                t = c.get('t')
                v = c.find('{%s}v' % NS)
                isel = c.find('{%s}is' % NS)
                if t == 's' and v is not None:
                    val = strings[int(v.text)]
                elif v is not None:
                    val = v.text
                    try:
                        val = float(val)
                    except (TypeError, ValueError):
                        pass
                elif isel is not None:
                    val = ''.join(x.text or '' for x in isel.iter('{%s}t' % NS))
                else:
                    val = ''
                grid[(r, col)] = val
                maxc = max(maxc, col)
                maxr = max(maxr, r)
        rows = []
        for r in range(1, maxr + 1):
            rows.append([grid.get((r, c), '') for c in range(maxc + 1)])
        sheets.append((name, rows))
    return sheets


def norm(v):
    """Cell -> stripped string for labels / names."""
    if isinstance(v, float):
        return '' if v == 0 else ('%g' % v)
    return str(v).strip()


def to_float(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def parse_period(text):
    m = re.search(r'ATTENDANCE\s*:\s*([A-Z]+)\s+(\d+)\s*-\s*(?:([A-Z]+)\s+)?(\d+),\s*(\d{4})', text, re.I)
    if not m:
        return None
    mo1 = MONTHS.get(m.group(1).upper())
    mo2 = MONTHS.get(m.group(3).upper()) if m.group(3) else mo1
    d1, d2, year = int(m.group(2)), int(m.group(4)), int(m.group(5))
    if not mo1 or not mo2:
        return None
    ws = datetime.date(year, mo1, d1).isoformat()
    we = datetime.date(year, mo2, d2).isoformat()
    return ws, we


def parse_site_blocks(rows):
    """Extract weekly payroll blocks (ANGELES / LUBAO) from one sheet."""
    blocks = []
    current = None

    for row in rows:
        cells = [norm(c) for c in row]

        # 1) Summary labels of the current block first (they can share a row
        #    with the next site marker). Labels sit at different columns per
        #    sheet (ANGELES blocks use col 8/9, LUBAO blocks col 9/10), so scan
        #    the whole row and take the first number to the right of the label.
        #    The first occurrence of a label wins, which keeps the trailing
        #    hand-typed running-total rows (e.g. rows below the TOTAL TOTAL)
        #    from overwriting the real values.
        if current is not None:
            for ci, c in enumerate(cells):
                lbl = c.upper()
                if lbl not in ('TOTAL PAYROLL', 'CASH ADVANCE', 'DEDUCTION', 'ADD. EXPENSES', 'TOTAL TOTAL'):
                    continue
                if lbl in current['summary']:
                    continue
                for v in cells[ci + 1:]:
                    f = to_float(v)
                    if f is not None:
                        current['summary'][lbl] = f
                        break

        # 2) Site marker starts a new block.
        site = None
        for c in cells:
            clean = c.replace(' ', '').upper()
            if clean in ('ANGELES', 'LUBAO'):
                site = clean
                break
        if site:
            current = {'site': site, 'period': None, 'budget': None, 'entries': [],
                       'summary': {}, 'collecting': False}
            blocks.append(current)
            continue
        if current is None:
            continue

        # 3) Period / budget markers.
        for c in cells:
            if 'ATTENDANCE:' in c.upper():
                current['period'] = parse_period(c)
                break
        if cells[1].upper() == 'BALI BINYE NA ENGR':
            current['budget'] = to_float(cells[2])

        # 4) Data table header toggles collection.
        if cells[1].upper() == 'NAME' and cells[2].upper() == 'POSITION':
            current['collecting'] = True
            continue
        if cells[2].upper().startswith('SUB TOTAL'):
            current['collecting'] = False
            continue
        if not current['collecting']:
            continue

        # 5) Worker data rows.
        name = cells[1]
        if not name:
            continue
        rate = to_float(cells[3])
        days = to_float(cells[4])
        ot = to_float(cells[6])
        cash_advance = to_float(cells[10]) or 0.0
        personal_cash_advance = to_float(cells[24]) or 0.0
        deduction = to_float(cells[26]) or 0.0

        # Attendance columns (Sun..Sat at col 15..21). Stored as a 7-char
        # code: digits = present, 'O' = absent, 'H' = half day, '.' = no data.
        attendance = []
        for c in cells[15:22]:
            if c in ('O', 'H', 'o', 'h'):
                attendance.append(c.upper())
            else:
                f = to_float(c)
                attendance.append('%g' % f if f is not None else '.')

        # Skip placeholder rows (empty rate/days/OT/CA) e.g. a name header row
        # with no numbers. Rows paid a fixed amount are captured via flat_pay.
        flat_pay = 0.0
        has_values = rate is not None or (days or 0) > 0 or (ot or 0) > 0 or cash_advance or personal_cash_advance or deduction
        if not has_values:
            gross = to_float(cells[9])
            if gross and gross > 0 and rate is None:
                flat_pay = round(gross, 2)
                has_values = True
            else:
                continue

        current['entries'].append({
            'name': name,
            'position': cells[2],
            'rate': round(rate, 4) if rate is not None else 0,
            'days': round(days or 0, 1),
            'ot_hours': round(ot or 0, 1),
            'cash_advance': round(cash_advance, 2),
            'personal_cash_advance': round(personal_cash_advance, 2),
            'deduction': round(deduction, 2),
            'attendance': ''.join(attendance),
            'flat_pay': round(flat_pay, 2),
        })

    return blocks


def main():
    src = sys.argv[1] if len(sys.argv) > 1 else r'C:\Users\jessi\Downloads\GENERAL PAYROLL.xlsx'
    repo_root = Path(__file__).resolve().parent.parent
    out_path = repo_root / 'data' / 'payroll_seed.json'

    sheets = load_sheets(src)
    seed_sites = []
    seen = set()

    for sheet_name, rows in sheets:
        if sheet_name.upper() == 'GENERAL PAYROLL':
            continue  # redundant combined sheet; per-week sheets are canonical
        for block in parse_site_blocks(rows):
            if not block['period'] or block['budget'] is None:
                print('SKIP block without period/budget on sheet %r: %s' % (sheet_name, block['site']))
                continue
            ws, we = block['period']
            key = (block['site'], ws)
            if key in seen:
                continue
            seen.add(key)
            site_obj = next((s for s in seed_sites if s['name'] == block['site']), None)
            if site_obj is None:
                site_obj = {'name': block['site'], 'payrolls': []}
                seed_sites.append(site_obj)
            summary = block['summary']
            budget = round(block['budget'], 2)
            payroll = round(summary.get('TOTAL PAYROLL') or 0, 2)
            cash_advance = round(summary.get('CASH ADVANCE') or budget or 0, 2)
            deduction = round(summary.get('DEDUCTION') or 0, 2)
            add_expenses = round(summary.get('ADD. EXPENSES') or 0, 2)
            total = round(summary.get('TOTAL TOTAL') or (payroll - budget - deduction + add_expenses), 2)
            site_obj['payrolls'].append({
                'week_start': ws,
                'week_end': we,
                'budget': budget,
                'cash_advance': cash_advance,
                'site_deduction': deduction,
                'add_expenses': add_expenses,
                'payroll_amount': payroll,
                'total_amount': total,
                'entries': block['entries'],
            })

    for s in seed_sites:
        s['payrolls'].sort(key=lambda p: p['week_start'])
    seed_sites.sort(key=lambda s: s['name'])

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps({'sites': seed_sites}, indent=2, ensure_ascii=False), encoding='utf-8')

    # Summary for verification against the spreadsheet.
    print('Wrote %s' % out_path)
    for site in seed_sites:
        print('SITE %s' % site['name'])
        for p in site['payrolls']:
            est = sum(e['days'] * e['rate'] + (e['rate'] / 8) * e['ot_hours'] + e['flat_pay'] for e in p['entries'])
            print('  %s -> %s | pay=%.2f est=%.2f budget=%s ca=%s ded=%s add=%s total=%s | %d workers' % (
                p['week_start'], p['week_end'], p['payroll_amount'], round(est, 2),
                p['budget'], p['cash_advance'], p['site_deduction'], p['add_expenses'],
                p['total_amount'], len(p['entries'])))


if __name__ == '__main__':
    main()
