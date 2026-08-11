-- ============================================================
-- Payroll System - MySQL / MariaDB schema
-- Create a database first, then import this file. Example:
--   CREATE DATABASE payroll CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
--   mysql -u root -p payroll < database/schema.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Sites (job sites / project locations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Employees (master worker list)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Site employees (a worker assigned to a site with position/rate)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL,
  employee_id INT NOT NULL,
  position VARCHAR(100) NOT NULL DEFAULT '',
  rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  UNIQUE KEY uniq_site_employee (site_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Payroll weeks (one row per site per week)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payrolls (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL,
  week_start DATE NOT NULL,
  week_end DATE NOT NULL,
  budget DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  site_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  add_expenses DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_payroll_week (site_id, week_start, week_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Payroll entries (per worker per week; snapshots position/rate)
-- attendance: 7-char S M T W T F S code (P/A/H/.)
-- ot_daily:   per-day OT hours CSV ("0,0,2.5,...") = prev week DTR OT
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
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
  UNIQUE KEY uniq_entry (payroll_id, site_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Daily time record (unique per worker + date; OT paid next payroll)
-- status: P = present, A = absent, H = half day, . = no data
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_employee_id INT NOT NULL,
  work_date DATE NOT NULL,
  status VARCHAR(1) NOT NULL DEFAULT '.',
  ot_hours DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_att_day (site_employee_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Engineer's personal cash advance ledger
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personal_cash_advances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_employee_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  advance_date DATE NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Worker transfers between sites for a week
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS worker_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_employee_id INT NOT NULL,
  to_site_id INT NOT NULL,
  days DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  week_start DATE NOT NULL,
  week_end DATE NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Users (passwords are BCRYPT-hashed)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(100) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY username (username),
  UNIQUE KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- DB-backed sessions (used on stateless hosts, e.g. Vercel)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) PRIMARY KEY,
  data TEXT NOT NULL,
  last_accessed INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
