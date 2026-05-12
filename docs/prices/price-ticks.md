# Price Ticks DB Operations

This document describes the MariaDB-backed price store used by daily reports.

## Purpose

Daily reports read prices from MariaDB table `price_ticks` in database `sqlite_replication`. This makes regenerated historical reports independent from the JSON price cache files under `main/data/price`.

The JSON files still exist for compatibility with the existing price UI, schedule resolver, and as the first source for historical backfill.

## Tables

The tables are created idempotently by `priceTicksEnsureTables()` in `main/prices/price_ticks_common.php`.

### `price_ticks`

One row per local date/hour:

```sql
local_date DATE NOT NULL
local_hour TINYINT UNSIGNED NOT NULL
price_at_utc DATETIME NULL
consumer_eur_per_kwh DECIMAL(10,6) NOT NULL
spot_eur_per_kwh DECIMAL(10,6) NULL
source VARCHAR(32) NOT NULL DEFAULT 'entsoe_v6'
samples_found TINYINT UNSIGNED NULL
fetched_at DATETIME NOT NULL
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
UNIQUE KEY uq_price_ticks_local_hour (local_date, local_hour)
```

### `price_fetch_log`

Tracks update/backfill attempts:

```sql
target_date DATE NOT NULL
run_type VARCHAR(16) NOT NULL
source VARCHAR(32) NOT NULL
success TINYINT(1) NOT NULL DEFAULT 0
rows_expected TINYINT UNSIGNED NOT NULL DEFAULT 24
rows_upserted TINYINT UNSIGNED NOT NULL DEFAULT 0
missing_hours TEXT NULL
started_at DATETIME NOT NULL
finished_at DATETIME NOT NULL
error_text TEXT NULL
```

## Configuration

Both scripts load `daily_report/.env` automatically when it exists, then use the same MariaDB environment variables as the daily report generator:

```text
MARIADB_HOST=127.0.0.1
MARIADB_PORT=3306
MARIADB_USER=root
MARIADB_PASSWORD=
MARIADB_DATABASE=sqlite_replication
```

ENTSO-E access uses `main/prices/config.json`:

```json
{
  "ENTSOE_SECURITY_TOKEN": "your-entsoe-api-token"
}
```

## Daily Updater

Command:

```powershell
php main\prices\update_price_ticks.php
```

Behavior:

- ensures `price_ticks` and `price_fetch_log` exist;
- checks yesterday, today, and tomorrow;
- skips dates that already have all 24 hours;
- fetches incomplete dates through ENTSO-E v6;
- upserts available hours;
- logs success/incomplete/failure per date;
- continues if one date fails.

Suggested cron timing:

```text
After 14:00 Europe/Amsterdam daily.
Running more often is safe because complete dates are skipped.
```

Example Linux cron:

```cron
15 14 * * * cd /path/to/zendure && php main/prices/update_price_ticks.php >> logs/price_ticks.log 2>&1
```

## Backfill

Command:

```powershell
php main\prices\backfill_price_ticks.php --start-date 2026-02-20
```

Optional arguments:

```powershell
php main\prices\backfill_price_ticks.php --start-date 2026-02-20 --end-date 2026-05-12
php main\prices\backfill_price_ticks.php --start-date 2026-02-20 --end-date 2026-05-12 --dry-run
```

Behavior per date:

1. If DB has 24 rows, skip as already complete.
2. If a complete JSON file exists, import it into `price_ticks`.
3. If JSON is missing or incomplete, fetch ENTSO-E v6.
4. Upsert whatever valid hours are available.
5. Log missing hours so the date can be retried later.

Backfill never deletes rows.

## Daily Report Dependency

`daily_report/tools/hourly_daily_grid_battery_report.py` now loads prices from `price_ticks` only. Missing DB rows become `null` hourly prices, so related cost values are also `null`.

Relevant report metadata:

- `price_source`: `db:price_ticks`
- `price_hours_available`: count of DB price rows loaded for the date
- `price_file_found`: retained for compatibility, true when at least one DB price row was found
