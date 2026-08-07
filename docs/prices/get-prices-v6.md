# Price API v6 Documentation

**Endpoint:** `main/prices/get_prices_v6.php`

This API returns today and tomorrow electricity prices in the existing v5-style JSON format. It uses **ENTSO-E** Document Type A44 as the source, stores compatible JSON cache files under `main/data/price/YYYYMM/priceYYYYMMDD.json`, and exposes reusable fetch functions for the DB-backed price tick updater.

## Overview

`get_prices_v6.php`:

- returns today and tomorrow hourly consumer prices in EUR/kWh;
- reads local JSON price files when present;
- fetches today from ENTSO-E if today's JSON file is missing;
- fetches tomorrow from ENTSO-E when the current Europe/Amsterdam hour is `>= 14` and tomorrow's JSON file is missing;
- can run as HTTP or CLI entrypoint;
- can now be safely included by CLI scripts without immediately emitting a JSON response.

The JSON files are still used by the mobile/schedule price UI and by the price backfill importer. Daily reports no longer depend on these files; they read prices from MariaDB table `price_ticks`.

## Configuration

The ENTSO-E API requires a security token in `main/prices/config.json`, which is git-ignored:

```json
{
  "ENTSOE_SECURITY_TOKEN": "your-entsoe-api-token"
}
```

The price tick updater/backfill scripts load `daily_report/.env` automatically when it exists. They use these MariaDB environment variables, defaulting to the existing daily-report database:

```text
MARIADB_HOST=127.0.0.1
MARIADB_PORT=3306
MARIADB_USER=root
MARIADB_PASSWORD=
MARIADB_DATABASE=sqlite_replication
```

## Output JSON

The response has four top-level keys:

- `today`: object or `null`, hour map for today.
- `tomorrow`: object or `null`, hour map for tomorrow.
- `dates`: `{ today: "YYYYMMDD", tomorrow: "YYYYMMDD" | null }`.
- `updateResults`: booleans showing whether today/tomorrow were newly fetched by this request.

Hour maps use two-digit local hour keys:

```json
{
  "00": 0.241,
  "01": 0.238,
  "23": 0.248
}
```

Values are consumer prices in EUR/kWh. They are derived from ENTSO-E EUR/MWh prices via `main/includes/price_conversion.php`.

## Stored JSON Price Files

When this API fetches a complete day, it saves:

```text
main/data/price/YYYYMM/priceYYYYMMDD.json
```

The file contains only the hour map, with keys `"00"` through `"23"` and consumer price values. These files remain the compatibility/cache layer for existing price UI and schedule-resolution code.

## Price Tick DB Integration

The reusable function:

```php
fetchEntsoeHourPricesForDate(string $dateStr, bool $saveToFile = true, bool $requireComplete = true): ?array
```

is used by:

- `main/prices/update_price_ticks.php`
- `main/prices/backfill_price_ticks.php`

Those scripts call it with `saveToFile=false` and `requireComplete=false`, so partial ENTSO-E responses can be stored in `price_ticks` and retried later.

When ENTSO-E still leaves a date incomplete, the updater falls back to EnergyZero via `get_prices_v7.php` (shared fetch logic in `energyzero_hour_prices.php`).

See [price-ticks.md](price-ticks.md) for the DB schema, cron command, and backfill process.

## CLI

Run the legacy JSON API from CLI:

```powershell
php main\prices\get_prices_v6.php
```

Run DB reconciliation:

```powershell
php main\prices\update_price_ticks.php
```

Run historical backfill:

```powershell
php main\prices\backfill_price_ticks.php --start-date 2026-02-20
```

## Data Source and Pricing

- Source: ENTSO-E Transparency Platform, Document Type A44, NL domain `10YNL----------L`.
- Resolution: 15-minute source periods, averaged into hourly local prices.
- Conversion:

```text
kwh_price = average_price_eur_mwh / 1000
consumer = (kwh_price + supplierMarkupEurPerKwh + energyTaxEurPerKwh) * vatMultiplier
```

Conversion values are read from `common/config/system.json` under `priceConversion`.
