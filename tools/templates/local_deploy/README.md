# Payroll System — Local / self-hosted deployment

This folder is a **self-contained copy** of the Payroll System that runs on any
plain PHP + MySQL/MariaDB host — **no Vercel required**. It is generated from
the main repo by `tools/build_local_deploy.php` (run `php tools/build_local_deploy.php`
from the repo root to refresh it after code changes).

## What's inside

- The full application: login, dashboard, sites, workers, DTR, payrolls, users.
- `database/schema.sql` — full SQL schema (also self-heals: missing tables are
  created on page load).
- `config/db_credentials.php.example` — copy to `config/db_credentials.php` on
  shared hosts (the real file is never committed).
- `.htaccess` — blocks direct web access to `config/`, `database/`,
  and `.log` files.
- **Not included:** `api/`, `vercel.json`, `tools/` and other Vercel/dev-only
  files. This copy uses plain PHP pages served directly by Apache.

## Requirements

- PHP 8+ with the `pdo_mysql` extension
- MySQL 5.7+ / MariaDB 10.3+

## Option A — XAMPP (localhost)

1. Copy this folder to `C:\xampp\htdocs\payroll`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Create the database once:

   ```sql
   CREATE DATABASE wip0 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

   (The app creates its tables automatically on the first page load.)

4. Open `http://localhost/payroll/`. First time only, run
   `http://localhost/payroll/create_user.php` to create the initial
   `admin` account (default password `admin123`), then log in and **change the
   password immediately** under **Manage Users**.
5. **Delete `create_user.php` after first use.**

## Option B — Shared host (InfinityFree, cPanel, etc.)

1. Create a MySQL database in your host's control panel (e.g.
   `if0_42499165_wip0`).
2. Upload the contents of this folder to `htdocs/` (InfinityFree) or
   `public_html/` (cPanel).
3. Copy `config/db_credentials.php.example` to `config/db_credentials.php` and
   fill in your real host, database, user and password:

   ```php
   $db_host = 'sql310.infinityfree.com';
   $db_port = '';
   $db_user = 'if0_42499165';
   $db_pass = 'your-password-here';
   $db_name = 'if0_42499165_wip0';
   $db_ssl  = '';
   ```

4. Visit your site URL. First time only, open `/create_user.php` to create the
   initial `admin` account, then log in and **change the password** under
   **Manage Users**.
5. **Delete `create_user.php` from the server after first use.**

## Database credentials resolution

Credentials are resolved in this order by `config/DBconnect.php`:

1. Environment variables `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`,
   `DB_NAME`, `DB_SSL`, `DB_SSL_CA`
2. `config/db_credentials.php` (the gitignored file above)
3. Local defaults: `localhost` / `root` / no password / `wip0`

So on XAMPP with the default `root` (no password) and a `wip0` database, you do
not need to configure anything.

Set `DB_SSL=1` when the host requires TLS on its public endpoint (Aiven, TiDB
Cloud, etc.). If `DB_SSL_CA` points to a CA bundle file it is used to verify the
server certificate; otherwise the connection is encrypted without certificate
verification.
