-- Add schedule rule encoding to replicated status_updates.
-- Values: NZ+, NZ-, NZ0, or fixed watts as text (e.g. 400, -2200).
-- NULL = event has no schedule rule (start/stop/Rescan) or pre-migration rows.
--
-- Deploy before automate starts exposing `rule` via SQLite replication.
-- Database: sqlite_replication (or MARIADB_DATABASE override).
--
-- db-replication client only runs CREATE TABLE IF NOT EXISTS; it does not ALTER
-- existing tables. Apply this script manually on the receiving MariaDB.

ALTER TABLE `status_updates`
  ADD COLUMN `rule` VARCHAR(5) NULL
  AFTER `electric_level`;

-- Idempotent / safe re-run variant:
--
-- SET @db := DATABASE();
-- SET @exists := (
--   SELECT COUNT(*)
--   FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = @db
--     AND TABLE_NAME = 'status_updates'
--     AND COLUMN_NAME = 'rule'
-- );
-- SET @sql := IF(
--   @exists = 0,
--   'ALTER TABLE `status_updates` ADD COLUMN `rule` VARCHAR(5) NULL AFTER `electric_level`',
--   'SELECT ''column rule already exists'' AS info'
-- );
-- PREPARE stmt FROM @sql;
-- EXECUTE stmt;
-- DEALLOCATE PREPARE stmt;
--
-- If a fresh sink was auto-created as LONGTEXT (SQLite TEXT mapping), normalize with:
-- ALTER TABLE `status_updates` MODIFY COLUMN `rule` VARCHAR(5) NULL;
