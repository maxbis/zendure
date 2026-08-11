# Daily Report Operations

This runbook describes how to fill and maintain the MariaDB tables used by the daily report stack.

Reports use a hybrid source:

- today is built live from `sqlite_replication.status_updates`;
- yesterday and older days are read from `sqlite_replication.hourly_report_inputs`;
- prices come from `sqlite_replication.price_ticks`.

Run commands from the repository root.

## Configuration

The scripts load `daily_report/.env` when it exists. These variables are used for MariaDB:

```text
MARIADB_HOST=127.0.0.1
MARIADB_PORT=3306
MARIADB_USER=your_db_user
MARIADB_PASSWORD=your_db_password
MARIADB_DATABASE=sqlite_replication
ENPHASE_HISTORY_DATABASE=enphase_history
ENPHASE_PRODUCTION_TABLE=production_hourly
ENPHASE_SYSTEM_ID=5053376
ENPHASE_PRODUCTION_SOURCE=production_micro
```

Price fetching also needs the ENTSO-E token in `main/prices/config.json`:

```json
{
  "ENTSOE_SECURITY_TOKEN": "your-entsoe-token"
}
```

## Initial Fill

Initial fill must run in this order: prices first, daily report aggregates second.

### 1. Fill Prices

Use the earliest date for which you want reports:

```bash
php main/prices/backfill_price_ticks.php --start-date 2026-04-01
```

Optional end date:

```bash
php main/prices/backfill_price_ticks.php --start-date 2026-04-01 --end-date 2026-05-12
```

Dry run:

```bash
php main/prices/backfill_price_ticks.php --start-date 2026-04-01 --dry-run
```

Precautionary single-day run:

```bash
php main/prices/backfill_price_ticks.php
```

Without arguments, the script targets yesterday in Europe/Amsterdam.

What it does:

- creates `price_ticks` and `price_fetch_log` if missing;
- skips dates that already have 24 hourly rows;
- imports complete JSON cache files when available;
- otherwise fetches ENTSO-E v6;
- upserts available hourly rows;
- logs missing hours for later retry.

### 2. Fill Daily Report Aggregates

After prices exist, fill `hourly_report_inputs`:

```bash
python3 daily_report/tools/update_hourly_report_inputs.py --start-date 2026-04-01
```

Optional end date:

```bash
python3 daily_report/tools/update_hourly_report_inputs.py --start-date 2026-04-01 --end-date 2026-05-12
```

On Windows:

```powershell
python daily_report\tools\update_hourly_report_inputs.py --start-date 2026-04-01
```

What it does:

- creates `hourly_report_inputs` and `hourly_report_inputs_update_log` if missing;
- reads raw `status_updates`;
- reads hourly prices from `price_ticks`;
- reads hourly production from `enphase_history.production_hourly`;
- recomputes each day and upserts one row per local hour;
- never deletes raw `status_updates`.

## Daily Updates

Daily maintenance also runs in this order: prices first, report aggregates second.

### 1. Update Prices

```bash
php main/prices/update_price_ticks.php
```

Default target window:

- yesterday;
- today;
- tomorrow.

The updater retries incomplete dates every run. If tomorrow is not available yet, it logs the incomplete state without failing the whole run.

Recommended timing: run after 14:00 Europe/Amsterdam, because next-day prices usually become available then. Running more often is safe.

### 2. Update Daily Report Aggregates

```bash
python3 daily_report/tools/update_hourly_report_inputs.py
```

Default target window:

- yesterday;
- today.

This is intentional:

- today is useful for validation and comparison;
- yesterday is recomputed after midnight so boundary interpolation can use the first rows of the new day.

Production APIs still build today live from `status_updates`, but keeping today in `hourly_report_inputs` fresh is useful for validation and for the moment today becomes yesterday.

The production cron runs on the production host. MariaDB is manually replicated from production to localhost for development and validation; localhost does not run this cron.

## Battery Flow PnL Migration and Backfill

Apply the additive schema migration first on localhost:

```bash
mysql -h127.0.0.1 -P3306 -uUSER -p sqlite_replication \
  < daily_report/sql/add_battery_flow_pnl_columns.sql
```

The migration adds nullable estimated-home-load and whole-Wh attribution fields, signed integer millieuro fields, a calculation status, and a method version. It does not modify existing report values.

Dry-run the reconstructable historical range:

```bash
python3 daily_report/tools/update_hourly_report_inputs.py \
  --start-date 2026-04-06 \
  --end-date YYYY-MM-DD \
  --pnl-only \
  --dry-run
```

Review the per-date status counts. When the output is acceptable, populate only the new fields:

```bash
python3 daily_report/tools/update_hourly_report_inputs.py \
  --start-date 2026-04-06 \
  --end-date YYYY-MM-DD \
  --pnl-only
```

The operation is idempotent and commits each date independently. Rerun the same range after corrected raw data, prices, production, or calculation code. Existing charge, discharge, grid, price, and battery values are not updated in `--pnl-only` mode. Estimated home load, battery attribution, PnL, status, and method version are overwritten; clearing them first is unnecessary.

Validate the result:

```sql
SELECT
  local_date,
  battery_pnl_status,
  COUNT(*) AS hours
FROM hourly_report_inputs
WHERE local_date >= '2026-04-06'
GROUP BY local_date, battery_pnl_status
ORDER BY local_date, battery_pnl_status;
```

```sql
SELECT local_date, local_hour
FROM hourly_report_inputs
WHERE battery_pnl_status = 'complete'
  AND (
    battery_charge_grid_wh + battery_charge_surplus_wh <> ROUND(charged_wh)
    OR battery_discharge_home_wh + battery_discharge_export_wh <> ROUND(discharged_wh)
    OR battery_flow_pnl_milli_eur <>
      battery_home_savings_milli_eur
      + battery_export_revenue_milli_eur
      - battery_charge_cost_milli_eur
  );
```

See `docs/daily_report/battery-flow-pnl.md` for the complete formula and status contract.

## Cron Example

Linux example:

```cron
# Prices: retry around and after ENTSO-E publication time.
15 14 * * * cd /path/to/zendure && php main/prices/update_price_ticks.php >> logs/price_ticks.log 2>&1
15 16 * * * cd /path/to/zendure && php main/prices/update_price_ticks.php >> logs/price_ticks.log 2>&1

# Report aggregates: keep yesterday/today refreshed.
*/15 * * * * cd /path/to/zendure && python3 daily_report/tools/update_hourly_report_inputs.py >> logs/hourly_report_inputs.log 2>&1
```

If using a virtual environment or a specific Python install, replace `python3` with the same Python that has `pymysql` installed.

## Verification

Check price coverage:

```sql
SELECT local_date, COUNT(*) AS hours
FROM price_ticks
GROUP BY local_date
ORDER BY local_date DESC
LIMIT 10;
```

Check report aggregate coverage:

```sql
SELECT local_date, COUNT(*) AS hours, SUM(source_rows) AS source_rows
FROM hourly_report_inputs
GROUP BY local_date
ORDER BY local_date DESC
LIMIT 10;
```

Check recent failures:

```sql
SELECT target_date, run_type, success, missing_hours, error_text, finished_at
FROM price_fetch_log
ORDER BY id DESC
LIMIT 20;
```

```sql
SELECT target_date, run_type, success, hours_upserted, error_text, finished_at
FROM hourly_report_inputs_update_log
ORDER BY id DESC
LIMIT 20;
```

## Report API Behavior

The production report APIs no longer create daily report JSON files:

- `daily_report/api/report_data.php` uses live `status_updates` for today and `hourly_report_inputs` for older dates;
- `daily_report/api/monthly_report_data.php` uses the same per-day rule;
- `daily_report/api/pnl_data.php` uses the same per-day rule;

Saved JSON reports under `daily_report/data` are legacy data only.
