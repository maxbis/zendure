# Application data-storage inventory

## Purpose

This is the reference inventory for the Zendure application's persisted data,
data-facing source files, upstream sources and downstream consumers. It supports
the planned full redesign by separating data that should become durable domain
records from configuration, caches, generated artifacts and fixtures.

Scope captured: the repository state on 2026-10-01. The `automate/` application
is deliberately excluded. Its HTTP API and SQLite-to-MariaDB replication boundary
are included only where a non-automation component depends on them.

There are no database files checked into this working tree. The application
connects at runtime to:

- A local SQLite database owned by the excluded automation service. Its path is
  supplied to the replication server through `SQLITE_DB_PATH`.
- MariaDB, default database name `sqlite_replication`, configured through
  `MARIADB_*` environment variables. It is the current durable reporting and
  price store.
- A separate MariaDB database/schema for Enphase hourly production, configured
  by `ENPHASE_HISTORY_DATABASE`, `ENPHASE_PRODUCTION_TABLE` and related values.

## Location

Persisted JSON and configuration live under these paths:

- `common/config/`
- `main/config/`
- `main/data/`
- `planner/data/`
- `daily_report/data/`
- `data/`
- `emulation/`

The source modules that read, write, transform or expose the data are primarily
in `common/`, `main/`, `planner/`, `app/`, `daily_report/`, `pathlab/` and
`db-replication/`.

## Inputs and outputs

### Shared configuration

- `common/config/system.json`
  - Stores installation-wide battery capacity and limits, forecast assumptions,
    scheduling limits, location/time zone and price-conversion settings.
  - Read and validated by `common/php/system_config.php` and
    `common/python/system_config.py`.
  - Delivered to the old schedule interface, new app, planner, daily reports and
    Path Lab as normalized in-process configuration.
  - `common/config/system.schema.json` defines the corresponding JSON Schema.
    It is validation/documentation input, not runtime state.

- `main/config/config.json`
  - Stores legacy web configuration, mainly endpoint locations and web-specific
    options.
  - Read by `main/includes/config_loader.php`; selected values are emitted by
    `main/charge_schedule_mobile.php`, proxies and Path Lab.
  - Its downstream endpoints include the automation HTTP API, the price endpoint
    and the schedule API.

- `main/prices/config.json`
  - Legacy price-fetch configuration.
  - Read by the older price scripts. Newer v6/v7 scripts embed their upstream
    endpoints and then write the common price-file layout.

### Schedules, rules and profile state

- `main/data/charge_schedule.json`
  - Canonical legacy schedule map, containing manually entered date/hour actions.
  - Read and written by `main/api/charge_schedule_api.php` and
    `main/data/api/data_api.php`; read by
    `main/api/calculate_schedule_api.php`,
    `main/api/backtest_schedule_api.php` and the schedule-condition resolver.
  - Delivered as JSON to the old schedule UI, the new app's schedule view and,
    through the configured schedule endpoint, the automation consumer.

- `main/data/charge_schedule_bak.json`
  - Retained backup copy of the legacy schedule. It has no production reader in
    the current source tree; treat it as recovery material, not a second source
    of truth.

- `main/data/charge_schedule_conditions.json`
  - Rule definitions and condition-based schedule entries.
  - Read by `main/data/resolve_schedule_conditions.php` and
    `main/api/evaluate_auto_profiles_api.php`; edited through the schedule/rule
    interfaces and delivered as resolved per-date schedule JSON.

- `main/data/charge_schedule_conditions_bak.json`
  - Backup of the condition rules. No production reader was found.

- `main/data/rule_profiles.json`
  - Named rule-profile configuration used to include or exclude rules by date.
  - Read by `main/data/resolve_schedule_conditions.php`; its selected profile is
    delivered as part of schedule-resolution responses.

- `main/data/rule_profile_auto_state.json`
  - Generated profile-selection state, including forecast-derived choices.
  - Read by `main/includes/rule_profile_auto.php` and schedule resolution.
  - The `.lock` file is an untracked write lock, not business data.

- `main/data/target_battery_state_cache.json`
  - Cache of planner-target state maintained by `main/data/api/data_api.php`.
  - It is delivered only as a cached API result and should be regenerable.

### Price data

- `main/data/price/<YYYYMM>/price<YYYYMMDD>.json`
  - 153 checked-in daily JSON price files at inventory time, including a small
    number of `-raw` source responses.
  - Read by `main/data/api/data_api.php`,
    `main/data/resolve_schedule_conditions.php`, legacy schedule calculations
    and `main/prices/price_ticks_common.php`.
  - Written by `main/prices/get_prices_v6.php` (ENTSO-E),
    `main/prices/get_prices_v7.php` (EnergyZero) and compatible Data API writes.
  - Delivered as daily price JSON to schedule/UI callers and used as the fallback
    input to MariaDB `price_ticks` ingestion.

- `main/prices/get_prices_v6.php`
  - Fetches Dutch day-ahead prices from ENTSO-E XML, normalizes them and writes
    the daily price JSON files.

- `main/prices/get_prices_v7.php` and `main/prices/energyzero_hour_prices.php`
  - Fetch prices from the EnergyZero API and write or return hourly price data.

- `main/prices/update_price_ticks.php`, `main/prices/backfill_price_ticks.php`
  - Read the JSON cache first and otherwise fetch an upstream price source.
  - Upsert normalized price rows and fetch audit records into MariaDB.

- MariaDB `price_ticks`
  - Durable hourly consumer and spot prices keyed by `(local_date, local_hour)`.
  - Written by `main/prices/price_ticks_common.php`; read by daily reports,
    app energy history and price-tick helpers.

- MariaDB `price_fetch_log`
  - Audit trail for price ingestion runs, missing hours and errors.
  - Written by `main/prices/price_ticks_common.php`; primarily operational data.

### Energy history, reporting and external production

- MariaDB `status_updates`
  - Automation-owned measurement/event history replicated from SQLite through
    `db-replication/server/` and `db-replication/client/`.
  - The non-automation components do not write it. `daily_report` tools use it
    to calculate hourly battery and grid flows.

- `db-replication/server/config.py`, `sqlite_introspection.py`, `replication.py`,
  `main.py`
  - Read the configured automation SQLite database and expose approved tables,
    schema and incremental rows over a token-protected HTTP API.
  - Deliver batches to `db-replication/client/server_api.py`.

- `db-replication/client/config.py`, `server_api.py`, `mariadb_sink.py`, `main.py`
  - Read replication batches and create/append matching MariaDB tables.
  - Deliver replicated rows to MariaDB. `db-replication/sql/add_status_updates_rule_column.sql`
    is a manual MariaDB migration for the `status_updates.rule` column.

- MariaDB `hourly_report_inputs`
  - Durable per-hour derived facts: battery charge/discharge, grid exchange,
    prices, solar production, P&L and completeness/status fields.
  - Created and upserted by `daily_report/tools/update_hourly_report_inputs.py`.
  - Read by `daily_report/includes/report_smart_common.php` and
    `main/includes/app_energy_history.php`; delivered through daily-report and
    app energy-history APIs.

- MariaDB `hourly_report_inputs_update_log`
  - Durable audit log for aggregate generation runs.
  - Written by `daily_report/tools/update_hourly_report_inputs.py`.

- MariaDB `production_hourly` in the configured Enphase history database
  - External solar-production input, maintained outside this repository.
  - Read by `daily_report/tools/update_hourly_report_inputs.py` and
    `daily_report/tools/hourly_daily_grid_battery_report.py`; combined with
    `status_updates` and `price_ticks` before writing the report aggregate.

- `daily_report/data/<YYYYMM>/daily_report_<YYYYMMDD>.json`
  - 29 checked-in daily-report JSON fixtures/snapshots at inventory time.
  - `daily_report/includes/report_api_common.php` can load these or regenerate
    them using `daily_report/tools/hourly_daily_grid_battery_report.py`.
  - Delivered to the desktop, mobile and monthly report pages. They are a
    fixture/fallback path; the current smart report normally reads MariaDB
    `hourly_report_inputs` first.

- `daily_report/sql/add_battery_flow_pnl_columns.sql`
  - Manual migration for derived P&L columns on `hourly_report_inputs`.
  - It changes schema only; it is not runtime data.

- `data/p1_hourly_energy.json`
  - Local hourly P1-energy data fixture/cache. No production reader was found
    outside testing/tooling, so it should not be considered an active source of
    truth until a runtime use is added.

### Forecast and UI caches

- `main/data/energy_graph_cache.json` and
  `main/data/energy_graph_cache_days_3.json`
  - Cached, transformed `wh_per_hour` responses.
  - Written/read by `main/api/energy_graph_proxy.php`; upstream is the configured
    automation HTTP endpoint and downstream is the graph UI.

- `main/data/shortwave_radiation_cache_<hash>.json`
  - Two checked-in cache files at inventory time; more may be generated per
    location/time-zone query.
  - Read/written by `main/api/shortwave_radiation_api.php` and read/written
    independently by `pathlab/api/path_data.php` using the same location-hash
    naming convention.
  - Upstream is Open-Meteo shortwave-radiation forecast data. Downstream is the
    app solar view, planner and Path Lab.

### Optimizer artifacts

- `planner/data/load_forecast_default.json`
  - Persisted household-load forecast records managed by `planner/storage.py`.
  - Read by the optimizer; produced when the planner stores updated forecast
    inputs. It is model input, not primary measurement history.

- `planner/data/optimizer_schedule_latest.json`
  - Latest executable optimizer plan.
  - Atomically written by `planner/shadow.py`; read, validated and selectively
    applied by `app/includes/optimizer_schedule.php`.
  - Delivered through `main/data/api/data_api.php` and the optimizer view when
    optimizer mode is active; exact manual schedule entries still take priority.

- Runtime-created `planner/data/optimizer_schedule.log`,
  `optimizer_runtime.log`, `optimizer_mode.json` and `optimizer_mode_audit.log`
  - JSON-lines plan history, planner runtime observations, selected mode and a
    mode-change audit trail. They are intentionally not checked in.
  - Written by `planner/shadow.py` and `app/includes/optimizer_schedule.php`;
    read by `app/includes/optimizer_log.php`,
    `app/includes/optimizer_runtime_log.php` and optimizer APIs/pages.

### Development-only and authentication files

- `emulation/p1_steps.json`
  - Scenario input for `emulation/p1_emulator.py`; it is delivered only by the
    local test emulator.

- `login/validkeys.txt`
  - Line-based validation keys read by `login/validate.php` and used to set the
    browser authentication state. It is a credential store, not application
    domain data, and must not be copied into a general business-data table.

## Flow and behavior

The dominant current flow is:

1. Price providers (ENTSO-E or EnergyZero) populate daily JSON compatibility
   files. The price-tick updater reads those files when complete, otherwise
   refetches upstream data, and upserts MariaDB price rows.
2. The automation service remains the operational source for live battery and
   P1 data. Its SQLite history is replicated into MariaDB `status_updates`.
3. The report updater joins replicated status history, MariaDB price ticks and
   externally maintained Enphase production. It materializes
   `hourly_report_inputs` for report and energy-history APIs.
4. The schedule system reads JSON schedule/rule/profile files and JSON prices,
   resolves a schedule, then exposes JSON to browser clients and the automation
   polling contract.
5. The planner reads APIs for live state, prices and forecast, keeps forecast and
   optimizer artifacts as files, and publishes an executable schedule artifact.
6. Forecast and graph proxies cache upstream HTTP JSON in local files to keep
   the UI responsive when an upstream service is unavailable.

## Edge cases and failure modes

- JSON schedule, rule and price files can be modified by separate requests with
  no database transaction across related files. Atomic writes reduce corruption
  for some paths, but do not provide cross-file consistency.
- `charge_schedule_bak.json` and `charge_schedule_conditions_bak.json` are not
  consumed in production; restoring them manually risks overwriting newer data.
- Price data currently exists in both JSON and MariaDB. The JSON files remain a
  legacy compatibility/cache layer, while reports use MariaDB. A redesign must
  prevent the two stores from independently accepting conflicting edits.
- The report aggregate's `(local_date, local_hour)` key cannot represent both
  occurrences of the autumn daylight-saving hour. A redesigned schema needs a
  UTC timestamp or an explicit time-zone offset in its unique key.
- Forecast and energy-graph cache files are disposable. Treating them as source
  of truth would make stale upstream data appear authoritative.
- The replication pipeline has a source-system boundary: directly sharing the
  automation SQLite file with web processes would create locking, availability
  and deployment coupling.
- Environment variables and `.env` files hold connection credentials. They are
  not included in this inventory and should remain secrets outside application
  tables.

## Recommendation for the redesign

Use one **logical application database** for durable, app-owned state and
history. MariaDB is the practical choice because it is already used for the
durable price and reporting data. This means one authoritative schema and one
data-access layer, not necessarily one physical store for every byte of data.

Move into that database:

- Schedule entries, condition rules, rule profiles and their selection state.
- Official and fetched hourly prices, including source and ingestion metadata.
- Derived hourly energy/report facts and their calculation/audit status.
- Optimizer plans, selected mode and mode-change audit events.
- Load forecasts when they need revision history or must be shared by services.
- Installation configuration that must be editable at runtime, with versioning
  and an audit trail. Keep static schema/default files in source control.

Keep outside the application database:

- Automation's operational SQLite database and device control state. Continue
  using a versioned HTTP/replication contract until that subsystem is explicitly
  redesigned; do not make web and automation share a writable database as an
  incidental migration.
- Secrets and login credentials. Use a secrets manager or the existing protected
  configuration mechanism; migrate login keys only through a separate security
  design.
- Short-lived HTTP caches. Keep them as files initially, or move them to a
  purpose-built cache such as Redis if multi-process/shared caching is needed.
- JSON export/import files, test fixtures, emulator scenarios, SQL migrations
  and static JSON schemas.

Recommended migration sequence:

1. Define a relational model with immutable IDs, UTC event timestamps, an
   installation/site key and source/audit columns. Make UTC timestamps the
   canonical hourly-event key to solve the daylight-saving ambiguity.
2. Introduce repositories/API endpoints that read the database but retain the
   current JSON response contracts. Migrate one domain at a time, starting with
   schedules/rules and price ticks.
3. Import existing JSON files once, record their source path and import time,
   validate counts and per-hour values, then make the database authoritative.
4. During a short transition, generate JSON compatibility exports from the
   database only. Never dual-write UI changes to JSON and MariaDB independently.
5. Move report and optimizer artifacts after their schemas and retention rules
   are agreed. Retire file readers only after API and automation compatibility
   checks pass.

This approach achieves uniform durable data without turning config, secrets,
caches and subsystem boundaries into an oversized and fragile single database.

## Related files

- [Data API](api/data-api.md)
- [Current configuration-key mapping](../config/config-keys-usage-analysis.md)
- [Shared system configuration](../app/shared-system-configuration.md)
- [Optimizer shadow mode](../planner/rolling-optimizer-shadow.md)
- [Energy costs and battery P&L](../app/energy-costs-and-battery-pnl.md)
