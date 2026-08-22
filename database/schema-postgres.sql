-- ============================================================
-- Payroll System - PostgreSQL schema (Supabase)
-- Paste this whole file into the Supabase SQL Editor and run it once.
-- (Dashboard -> SQL Editor -> New query -> paste -> Run)
-- ============================================================

-- ------------------------------------------------------------
-- Sites (job sites / project locations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
  id SERIAL PRIMARY KEY,
  name VARCHAR(100) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Employees (master worker list)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
  id SERIAL PRIMARY KEY,
  name VARCHAR(100) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Site employees (a worker assigned to a site with position/rate)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_employees (
  id SERIAL PRIMARY KEY,
  site_id INT NOT NULL,
  employee_id INT NOT NULL,
  position VARCHAR(100) NOT NULL DEFAULT '',
  rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT uniq_site_employee UNIQUE (site_id, employee_id)
);

-- ------------------------------------------------------------
-- Payroll weeks (one row per site per week)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payrolls (
  id SERIAL PRIMARY KEY,
  site_id INT NOT NULL,
  week_start DATE NOT NULL,
  week_end DATE NOT NULL,
  budget DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  site_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  add_expenses DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uniq_payroll_week UNIQUE (site_id, week_start, week_end)
);

-- ------------------------------------------------------------
-- Payroll entries (per worker per week; snapshots position/rate)
-- attendance: 7-char S M T W T F S code (P/A/H/.)
-- ot_daily:   per-day OT hours CSV ("0,0,2.5,...") = prev week DTR OT
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_entries (
  id SERIAL PRIMARY KEY,
  payroll_id INT NOT NULL,
  site_employee_id INT NOT NULL,
  days_worked DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  ot_hours DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  cash_advance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  personal_cash_advance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  flat_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  position VARCHAR(100) NOT NULL DEFAULT '',
  rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  attendance VARCHAR(32) NOT NULL DEFAULT '',
  ot_daily VARCHAR(32) NOT NULL DEFAULT '',
  CONSTRAINT uniq_entry UNIQUE (payroll_id, site_employee_id)
);

-- ------------------------------------------------------------
-- Daily time record (unique per worker + date; OT paid next payroll)
-- status: P = present, A = absent, H = half day, . = no data
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
  id SERIAL PRIMARY KEY,
  site_employee_id INT NOT NULL,
  work_date DATE NOT NULL,
  status VARCHAR(1) NOT NULL DEFAULT '.',
  ot_hours DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uniq_att_day UNIQUE (site_employee_id, work_date)
);

-- ------------------------------------------------------------
-- Engineer's personal cash advance ledger
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personal_cash_advances (
  id SERIAL PRIMARY KEY,
  site_employee_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  advance_date DATE NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Worker transfers between sites for a week
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS worker_transfers (
  id SERIAL PRIMARY KEY,
  site_employee_id INT NOT NULL,
  to_site_id INT NOT NULL,
  days DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  week_start DATE NOT NULL,
  week_end DATE NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Users (passwords are BCRYPT-hashed)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id SERIAL PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(100) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT users_username_key UNIQUE (username),
  CONSTRAINT users_email_key UNIQUE (email)
);

-- ------------------------------------------------------------
-- DB-backed sessions (used on stateless hosts, e.g. Vercel)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) PRIMARY KEY,
  data TEXT NOT NULL,
  last_accessed INT NOT NULL
);
