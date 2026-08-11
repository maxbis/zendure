-- Add versioned battery-flow attribution and PnL fields to the durable hourly aggregate.
-- Energy components are stored as whole Wh. Money components are signed millieuros.

ALTER TABLE `hourly_report_inputs`
  ADD COLUMN IF NOT EXISTS `estimated_home_load_wh` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `battery_charge_grid_wh` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `battery_charge_surplus_wh` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `battery_discharge_home_wh` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `battery_discharge_export_wh` INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `battery_charge_cost_milli_eur` INT NULL,
  ADD COLUMN IF NOT EXISTS `battery_home_savings_milli_eur` INT NULL,
  ADD COLUMN IF NOT EXISTS `battery_export_revenue_milli_eur` INT NULL,
  ADD COLUMN IF NOT EXISTS `battery_flow_pnl_milli_eur` INT NULL,
  ADD COLUMN IF NOT EXISTS `battery_pnl_status` VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS `battery_pnl_method_version` SMALLINT UNSIGNED NULL;
