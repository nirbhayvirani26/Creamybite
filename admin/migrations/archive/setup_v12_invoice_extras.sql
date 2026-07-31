-- ============================================================
--  Creamy Bite – Setup v12: invoice discount type
--
--  Discount can now be a fixed amount or a percentage. The TYPE and the
--  entered VALUE are both stored, so an invoice showing "10% off" still
--  says 10% when reopened, rather than being flattened to the cash amount
--  it happened to work out to on the day.
--
--  invoices.discount keeps holding the calculated CASH amount, so every
--  existing total and report stays correct without a backfill.
-- ============================================================

ALTER TABLE `invoices`
  ADD COLUMN `discount_type`  ENUM('fixed','percent') NOT NULL DEFAULT 'fixed' AFTER `discount`,
  ADD COLUMN `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_type`;

-- Existing rows are fixed-amount discounts; mirror the cash figure across.
UPDATE `invoices` SET `discount_value` = `discount` WHERE `discount_value` = 0 AND `discount` > 0;
