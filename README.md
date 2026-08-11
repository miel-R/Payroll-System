# Payroll System

A PHP + MySQL weekly payroll application for construction sites, mirroring the
layout and math of the source spreadsheet ("GENERAL PAYROLL.xlsx").

- Track **sites**, **workers**, **payroll weeks**, and a per-day **DTR**.
- Attendance is stored per day (`P` = present, `A` = absent, `H` = half day,
  `.` = no data); days and OT hours roll up from the DTR.
- **OT is paid on the next payroll** — the OT a worker records in week X is
  paid on week X+1.
- Money fields: Cash Advance (budget "BALI BINYE NA ENGR"), personal cash
  advance ledger, site deduction, add. expenses, and flat pay overrides.
- Two roles: **admin** (full access) and **finance** (DTR entry + view/print).
- Server-rendered pages with progressive AJAX (fast saves + toasts) and a
  light/dark sidebar theme.

## Tech stack

- PHP 8+ with PDO (MySQL / MariaDB)
- Bootstrap 5.3 (CDN), Bootstrap Icons, Google Fonts (Inter)
- Local CSS/JS in `assets/css/app.css` and `assets/js/app.js`

## Local setup (XAMPP)

1. Start Apache + MySQL in XAMPP.
2. Create the database and tables:

   ```sql
   CREATE DATABASE wip0 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

   ```bash
   mysql -u root -p wip0 < database/schema.sql
   ```

   (Without a password on a fresh XAMPP, `mysql -u root wip0 < database/schema.sql`.)

3. Copy the project into `C:\xampp\htdocs\payroll` (or point Apache at it) and
   open `http://localhost/payroll/`.
4. Log in — the app creates tables it needs on first load, but to load the
   sample data go to `http://localhost/payroll/import_seed.php` and click
   **Import Seed Data** (this wipes payroll data first, so run it once).
5. Create accounts at `http://localhost/payroll/users.php`.

Database credentials are resolved in this order (`config/DBconnect.php`):

1. Environment variables `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`,
   `DB_NAME`, `DB_SSL`, `DB_SSL_CA`
2. `config/db_credentials.php` (gitignored — copy from
   `config/db_credentials.php.example`)
3. Local defaults: `localhost` / `root` / no password / `wip0`

Set `DB_SSL=1` when the host requires TLS on its public endpoint (Aiven,
TiDB Cloud, etc.). If `DB_SSL_CA` points to a CA bundle file it is used to
verify the server certificate; otherwise the connection is encrypted without
certificate verification (the practical path on Vercel, which cannot mount a
CA file).

## Deploying to Vercel

Vercel does not run PHP natively. This project uses the community
[`vercel-php@0.9.0`](https://github.com/vercel-community/php) runtime (PHP 8.5
with `pdo_mysql` and `session`), wired up via `vercel.json` and an
`api/index.php` front controller.

**Requirements**

- A MySQL/MariaDB host reachable from the internet. Vercel functions cannot
  connect to InfinityFree's MySQL (it blocks external connections), so use a
  host that allows remote connections — e.g. a VPS, Railway, Aiven (free
  tier), TiDB Cloud Serverless, or db4free.net.
- The `sessions` table in `database/schema.sql` is used for logins because
  Vercel functions are stateless (see below).

**Steps**

1. Push this repo to GitHub (or deploy directly from the folder).

2. Install the Vercel CLI and log in:

   ```bash
   npm i -g vercel
   vercel login
   ```

3. Create the tables on your remote database:

   ```bash
   mysql -h <DB_HOST> -u <DB_USER> -p <DB_NAME> < database/schema.sql
   ```

4. Set environment variables in the Vercel dashboard
   (Project → Settings → Environment Variables), or pass them on the CLI:

   | Variable            | Value                                    |
   | ------------------- | ---------------------------------------- |
   | `DB_HOST`           | your MySQL host                          |
   | `DB_PORT`           | your MySQL port (e.g. `25158` on Aiven)  |
   | `DB_USER`           | your MySQL user                          |
   | `DB_PASSWORD`       | your MySQL password                      |
   | `DB_NAME`           | your database name (e.g. `defaultdb`)    |
   | `DB_SSL`            | `1` (Aiven/TiDB require TLS)             |
   | `PAYROLL_DB_SESSIONS` | `1` (enables DB-backed sessions)       |

5. Deploy:

   ```bash
   vercel --prod
   ```

6. Create an initial admin user in your database (passwords are BCRYPT-hashed),
   or deploy first and use `users.php` after logging in with a seeded account.

### Using Aiven's free MySQL tier

1. Create an account at aiven.io → **Create service** → **MySQL** (free tier,
   1 GB storage / 1 GB RAM / 76 connections, no credit card needed).
2. Wait for the service status to show **Running**, then open
   **Overview → Connection information → MySQL**. You'll see a URI like:

   ```
   mysql://avnadmin:PASSWORD@mysql-xxxx-remielbaking19-xxxx.a.aivencloud.com:25158/defaultdb?ssl-mode=REQUIRED
   ```

   Use `mysql-...aivencloud.com` as `DB_HOST`, `25158` as `DB_PORT`,
   `avnadmin` as `DB_USER`, the password as `DB_PASSWORD`, `defaultdb` as
   `DB_NAME`, and `DB_SSL=1`.

3. Import the schema (the `--ssl-mode=REQUIRED` flag is mandatory):

   ```bash
   mysql --ssl-mode=REQUIRED -h mysql-xxxx.aivencloud.com -P 25158 -u avnadmin -p defaultdb < database/schema.sql
   ```

4. Set the 7 environment variables above in Vercel and deploy.

> The free Aiven service powers off after a long period of inactivity
> (it auto-reactivates on the next connection) and is single-node, so it's
> meant for small workloads — a good fit for this app.

**Why DB-backed sessions?** Vercel functions are stateless — PHP file sessions
live on an ephemeral filesystem, so `$_SESSION` would not survive between
requests. When `PAYROLL_DB_SESSIONS=1`, `config/session.php` registers a
`session_set_save_handler` that stores sessions in the shared `sessions`
table. Locally (no env var) the app keeps native file sessions.

**Local preview with the Vercel router**

```bash
php -S localhost:8000 api/index.php
```

## Project structure

```
config/DBconnect.php    DB credentials resolution + PDO
config/DBgetPDO.php     generic DB helpers + auth/user functions
config/DBpayroll.php    sites/employees/payroll functions, schema self-heal
config/session.php      DB-backed sessions for serverless
inc/header.php          shared layout: sidebar, auth gate, CSRF
inc/footer.php          shared footer + scripts
assets/css/app.css      design system (light/dark theme)
assets/js/app.js        AJAX forms, toasts, confirm modal, DTR shortcuts
api/index.php           Vercel front controller
database/schema.sql     full SQL schema
data/payroll_seed.json  seed data parsed from the spreadsheet
tools/                  xlsx -> json parser, DTR test filler
```

## Payroll math

- OT rate = daily rate / 8; BASIC = rate × days; OT PAY = OT rate × OT hrs;
  GROSS = basic + OT pay (a positive `flat_pay` overrides the time math).
- Net per worker = gross − cash advance − personal cash advance.
- Site totals: `TOTAL PAYROLL = sum(gross)`; `TOTAL TOTAL = payroll − budget −
  site_deduction + add_expenses`.

## Verifying the totals

Expected weekly totals (April 2026 seed data):

- ANGELES: 86525 / 97875 / 94281.25 payroll; 20000 / 17000 / 17000 budget;
  1000 / 2000 / 2000 deduction; 0 / 845 / 1000 add. exp.
- LUBAO: 40787.50 / 53331.25 / 53900 payroll; 6000 / 13000 / 13000 budget;
  5000 / 1500 / 3500 deduction; 0 / 0 / 0 add. exp.
