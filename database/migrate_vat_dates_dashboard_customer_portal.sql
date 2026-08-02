-- AutoShop Management System — migration for the July 29, 2026 review changes
-- Run this against your LIVE production database before deploying the new code:
--
--   mysql -u USER -p autoshop < database/migrate_2026_07_29.sql
--
-- Safe to run once. Existing invoices are left with their current total/balance
-- (no VAT is retroactively added to invoices already generated); only new
-- invoices generated after this migration will carry the VAT/NHIL/GETFund
-- breakdown, since `jobcards_invoice()` now populates those columns itself.

-- 1) Vehicle registration numbers must be unique (case-insensitive; the app
--    normalises to uppercase on save, so a plain UNIQUE index is sufficient).
--    If this fails with a duplicate-key error, first find and resolve the
--    existing duplicate registration numbers, e.g.:
--      SELECT reg_number, COUNT(*) FROM vehicles GROUP BY UPPER(reg_number) HAVING COUNT(*) > 1;
ALTER TABLE vehicles
  ADD UNIQUE KEY uq_vehicles_reg_number (reg_number);

-- Optional but recommended: normalise existing registration numbers to
-- uppercase so they match how the app will store new ones.
UPDATE vehicles SET reg_number = UPPER(TRIM(reg_number));

-- 2) VAT / NHIL / GETFund breakdown on invoices (Ghana VAT Act 2025 / Act 1151,
--    effective 1 January 2026 — 15% VAT + 2.5% NHIL + 2.5% GETFund, all on the
--    same taxable value).
ALTER TABLE invoices
  ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER parts_total,
  ADD COLUMN vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN nhil_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER vat_rate,
  ADD COLUMN getfund_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER nhil_rate,
  ADD COLUMN vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER getfund_rate,
  ADD COLUMN nhil_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER vat_amount,
  ADD COLUMN getfund_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER nhil_amount;

-- Backfill subtotal on existing invoices so the invoice view has something
-- sensible to show under "Subtotal" (labour + parts; tax fields stay 0 for
-- invoices generated before this migration).
UPDATE invoices SET subtotal = labour_total + parts_total WHERE subtotal = 0;
