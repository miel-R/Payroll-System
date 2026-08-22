# AGENTS.md

Conventions for the PHP Payroll System in this repo.

## Project layout

- `public/index.php` - login page (public)
- `dashboard.php`, `sites.php`, `site_workers.php`, `payrolls.php`,
  `payroll.php`, `payroll_entries.php`, `ca_history.php`, `payroll_form.php`, `payslip.php`, `dtr.php`, `users.php` - authenticated pages (all in `public/`)
- `public/dtr.php` - daily time record: pick a site + day, mark each worker P/A/H
  and their OT hours. Payroll days and paid OT are derived from these rows.
- `public/logout.php`, `contact.php`, `ajax.php` (AJAX endpoint), `test_db.php` - utility endpoints; CLI scripts live in `tools/`
- `src/inc/header.php`, `src/inc/footer.php` - shared authenticated layout
  (set `$page_title` and `$active_page` = `dashboard` | `sites` | `dtr` | `users` first)
- `src/config/DBconnect.php` - `dbCreds()` resolves credentials in order:
  env vars (`DB_HOST`/`DB_USER`/`DB_PASSWORD`/`DB_NAME`) -> gitignored
  `src/config/db_credentials.php` -> local defaults (localhost/root//wip0).
  `dbconnect()` connects lazily and reuses a global `$pdo`.
- `src/config/session.php` - `payroll_session_start()`; when env
  `PAYROLL_DB_SESSIONS=1` (Vercel) it registers a DB-backed session handler
  on the `sessions` table, otherwise native file sessions. All pages call
  this instead of `session_start()`.
- `src/config/DBgetPDO.php` - PDO helpers (`dbFetchAll`, `dbFetchOne`, `dbInsert`,
  `dbUpdate`, `dbDelete`, `dbExecute`, `dbTableExists`) + user functions
  (incl. roles: `dbEnsureUserRoleColumn()`, `dbUpdateUserRole()`,
  `currentUserRole()`, `requireRole()`)
- `database/schema.sql` - full SQL schema (all tables + `sessions`).
- `api/index.php` + `vercel.json` - Vercel deployment: front controller routes
  whitelisted page requests to the `public/*.php` scripts and serves `public/assets/`
  itself (catch-all route, `vercel-php@0.9.0` runtime).
- `src/config/DBpayroll.php` - site/employee/payroll functions + calc helpers;
  also `dbEnsurePayrollSchema()` (self-healing schema migration, called from the header on every page load), DTR attendance helpers
  (`dbSaveAttendance`, `dbGetAttendanceForDate`, `dbWeekAttendanceByWorker`),
  the personal cash advance ledger (`dbAddPersonalCashAdvance`,
  `dbDeletePersonalCashAdvance`, `dbGetPersonalCashAdvances`,
  `dbPersonalCaBalance`) and worker transfers
  (`dbAddWorkerTransfer`, `dbGetWorkerTransfers`).
- `public/assets/` - `css/app.css` (app design system) and `js/app.js` (AJAX layer)
  are local. Bootstrap 5.3.8 and Bootstrap Icons 1.11.3 are loaded from the
  jsdelivr CDN (with SRI) in `src/inc/header.php`, `src/inc/footer.php`, `public/index.php`,
  and `public/contact.php`. `css/index_style.css` is used only by the login page.
- AJAX conventions: forms with `data-ajax` submit via fetch and swap
  `#app-content`; `data-confirm` opens the themed confirm modal; flash rows
  carry class `flash-toast` (converted to toasts by `app.js`). Every POST form
  must include `csrf_field()` (header.php rejects bad tokens with HTTP 419).
  Live payroll math lives in `app.js` (`bindPayrollForm`), not inline scripts.

## Key rules

- Every page begins with `payroll_session_start()` (via `src/config/session.php`,
  or `inc/header.php` which does it) and redirects to `index.php` when not
  logged in.
- DB connections are lazy: `require_once` only defines functions; call a helper
  to actually connect. Never connect at include time.
- Use the helper functions, never raw PDO where a helper exists.
- All user output goes through `htmlspecialchars()`. Escape everything.
- Money is stored as DECIMAL and formatted with `prMoney()` (2 decimals).
- Pages that hit tables must tolerate an unavailable DB: wrap initial loads in
  `try/catch (PDOException)` so pages render (with a warning) instead of white-screening.

## Roles

- Two roles on `users.role` (VARCHAR, default `admin`, column self-heals via
  `dbEnsureUserRoleColumn()`, which `dbEnsurePayrollSchema()` also calls on every
  page load). `index.php` stores the role in `$_SESSION['role']` at login.
- `admin` - full access: sites, workers, payroll add/edit/delete, editable day
  grid in `payroll_form.php`, and the `users.php` user manager.
- `finance` - DTR entry + view/print only: can use `dtr.php` and
  `payroll_view.php`, browse `sites.php`/`site_workers.php`/`payrolls.php`
  read-only. Add/edit/delete forms and buttons are hidden, POSTs are rejected,
  and `payroll_form.php`/`users.php` redirect to `dashboard.php`.
- Page gates use `currentUserRole()` / `requireRole('admin')`; per-page POST
  guards use `$is_admin = currentUserRole() === 'admin'`.
- Create users + set roles in `users.php` (admin only).

## Payroll math (mirrors the spreadsheet)

- OT rate = daily rate / 8; BASIC = rate x days; OT PAY = OT rate x OT hrs;
  GROSS = basic + OT pay. A positive `flat_pay` overrides the time math.
- Per-worker net = gross - cash advance - personal cash advance.
- Site totals: TOTAL PAYROLL = sum(gross); CASH ADVANCE = budget
  ("BALI BINYE NA ENGR"); TOTAL TOTAL = payroll - budget - site_deduction + add_expenses.
- `payroll_entries` snapshots `position`/`rate` per week so old payrolls stay
  correct after a worker is edited.
- `personal_cash_advance` is the engineer's per-worker cash advance
  (col24 "CA KANG ENGR") - the amount recovered from that week's pay; it
  reduces the worker's net. The running ledger is `personal_cash_advances`
  (display-only balance = advances given - recovered).
- `payroll_entries.ot_daily` stores the 7 per-day OT-hour values as a CSV
  (`0,0,2.5,...`). `prNormAtt()` normalizes a stored attendance code to
  `P/A/H/.`, `prOtDailyArray()` splits the ot_daily CSV.
- Attendance/OT entry (Sun..Sat order, `S M T W T F S`):
  - `dtr.php` is the primary place days/OT are entered: pick a site + day and
    mark each worker `P/A/H/.` plus OT hours. Rows live in the `attendance`
    table (unique per worker+date, empty rows are deleted).
  - `payroll_form.php` (admin only) ALSO edits days/OT: its per-day grid is
    editable and saving writes the attendance back to the DTR, then saves the
    money fields (Cash Adv / Per. Cash Adv / Deduction / Flat Pay) + Personal CA
    and Transfer. OT Hrs paid stay the PREVIOUS week's DTR OT.
  - `payroll_view.php` shows the week's day codes per day (read-only for both roles).
- OT is paid on the NEXT payroll: the OT hours a worker records in week X are
  paid in week X+1's payroll. `payroll_form.php`/`payroll_view.php` show the
  PREVIOUS week's DTR OT as the OT to pay; `payroll_entries.ot_daily` snapshots
  those per-day OT values.
- Week rollup helpers: `dbWeekAttendanceByWorker($site_id, $ws, $we)` returns
  per worker `codes` (7-char), `days` (P=1, H=0.5), `ot_total`, `ot_daily`.
- Worker transfers: `payroll_form.php` "Transfer" action writes a
  `worker_transfers` row, ensures the worker exists on the target site
  (copies position/rate), creates the target site's payroll week if missing,
  and adds a blank entry so their days can be entered there.

## Dev tools

- `tools/fill_test_dtr.php` (CLI, one-time) fills DTR attendance (prev week full
  + current week up to today, `P` with sample OT) for ALL workers on ALL sites
  so the DTR/payroll screens can be tested. `--local` targets the local wip0 DB,
  default targets production; `--dry` previews. Delete from the server after
  running there.

## Verification

- Syntax check: `php -l <file>` on every modified PHP file.
- Expected weekly totals from the spreadsheet (April 2026):
  - ANGELES: 86525 / 97875 / 94281.25 payroll; 20000 / 17000 / 17000 budget;
    1000 / 2000 / 2000 deduction; 0 / 845 / 1000 add. exp.
  - LUBAO: 40787.50 / 53331.25 / 53900 payroll; 6000 / 13000 / 13000 budget;
    5000 / 1500 / 3500 deduction; 0 / 0 / 0 add. exp.
