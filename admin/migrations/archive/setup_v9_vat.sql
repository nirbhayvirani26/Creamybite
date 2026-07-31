-- ============================================================
--  Creamy Bite – Setup v9: VAT on trade orders
--
--  Trade partners who have a VAT number on their profile are charged
--  VAT at TRADE_VAT_RATE (20%). Both the amount and the VAT number in
--  force at the time are stored on the order, so a later edit to the
--  partner's profile never rewrites the history of a past invoice.
-- ============================================================

ALTER TABLE `orders`
  ADD COLUMN `vat_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `delivery_charge`,
  ADD COLUMN `vat_number` VARCHAR(50)   NOT NULL DEFAULT ''   AFTER `vat_amount`;
