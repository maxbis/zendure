# Historical household-usage profile

## Purpose

The household-usage profile updater creates a cached 24-hour P60 profile from historical household energy observations. The rolling optimizer consumes the profile when it passes strict validation and otherwise uses the configured preset profile.

The configured `forecast.defaultHouseholdUsageWByHour` values remain the operational fallback and are never modified by this process.

## Location

- Generator: `planner/update_household_usage_profile.py`
- Runtime selector: `planner/household_usage.py`
- Dependency-free database fallback: `planner/read_household_usage_rows.php`
- Generated profile: `planner/data/household_usage_profile.json`
- Source aggregate updater: `daily_report/tools/update_hourly_report_inputs.py`
- Source table: `sqlite_replication.hourly_report_inputs`
- Configuration fallback: `common/config/system.json`
- Configured profile path: `forecast.householdUsageProfilePath`

## Inputs and outputs

The generator reads `estimated_home_load_wh` for completed local dates from `hourly_report_inputs`. This field is the rounded, non-negative hourly household-load estimate derived from solar production, grid import, grid export, battery discharge, and battery charge.

The output contains:

- schema version;
- timezone and generation timestamp;
- first and last selected source dates;
- number of completed days used;
- percentile, lookback, sample threshold, and rounding step;
- one record for each local hour from `00` through `23`;
- calculated watt value, sample count, source, and configured fallback per hour;
- the list of hours that used configuration fallback.

The generated JSON file is ignored by Git and contains no database credentials.

## Flow and behavior

1. Load database settings from `daily_report/.env` and environment overrides.
2. Load timezone and the configured 24-hour fallback profile from shared system configuration.
3. Read recent non-null `estimated_home_load_wh` rows before today.
4. Keep dates that contain exactly one valid observation for every local hour.
5. Require yesterday to be one of those complete dates.
6. Select the latest 30 complete dates by default.
7. Calculate the linearly interpolated P60 independently for every clock hour.
8. Round historical values to the nearest 10 W.
9. Use the configured value when an hour has fewer than 14 samples.
10. Validate that all 24 output hours exist.
11. Atomically replace the cached profile only after the complete JSON document has been written and synced.

For each optimizer run, the runtime selector:

1. Reads the path configured by `forecast.householdUsageProfilePath` relative to the repository root.
2. Requires schema version 1, method `p60`, the configured timezone, a valid generation timestamp, yesterday as `source_end_date`, and exactly 24 finite non-negative hourly values.
3. Uses all 24 profile values when every check passes.
4. Uses all 24 `forecast.defaultHouseholdUsageWByHour` values when any check fails. It never mixes a partial profile with configured values.
5. Records the selected source, actual 24 values, profile metadata, path, and fallback reason in the optimizer log and published schedule.

Run the updater from the repository root:

```bash
python3 -m planner.update_household_usage_profile
```

The updater uses `pymysql` when it is installed. When it is not installed, it automatically reads the same data through the project's existing PHP/PDO database connection. This means the command also works in the standard local project environment without adding a global Python package.

Preview without changing the cached profile:

```bash
python3 -m planner.update_household_usage_profile --dry-run
```

The intended production order is:

```bash
python3 daily_report/tools/update_hourly_report_inputs.py && \
python3 -m planner.update_household_usage_profile
```

The profile calculation reads at most a small recent source window and should run after the hourly aggregate updater rather than on every optimizer invocation.

## Edge cases and failure modes

- When yesterday does not contain 24 valid observations, then the command exits with status `2` and leaves the previous profile unchanged.
- When no complete historical day exists, then the command exits with status `2` and leaves the previous profile unchanged.
- When one hour has fewer than the configured minimum samples, then that hour uses the shared configured fallback and is listed in `fallback_hours`.
- When the database, configuration, or output operation fails, then the command exits with status `1` and leaves an already published profile unchanged.
- When neither `pymysql` nor the PHP CLI with PDO MySQL is available, then the command exits with status `1` and reports both supported database paths.
- When a date contains duplicate hours, then that date is excluded.
- When a daylight-saving transition does not contain exactly 24 unique local hours, then that date is excluded from the profile.
- When concurrent runs finish at nearly the same time, then atomic replacement prevents readers from seeing partial JSON.
- When the profile is missing, unreadable, malformed, stale, has a different timezone or does not contain exactly 24 valid values, then the optimizer continues with the complete configured preset profile.
- When the local date changes before the daily profile update finishes, then the previous profile is stale and the optimizer temporarily uses the configured preset until a profile sourced through yesterday is published.

## Related files

- `planner/config.py` loads shared configuration and the fallback household profile.
- `planner/household_usage.py` validates the generated profile and selects either historical P60 or the configured preset.
- `planner/read_household_usage_rows.php` provides the PHP/PDO fallback used when `pymysql` is unavailable.
- `planner/forecast.py` defines the planner's existing date-specific load-forecast contract.
- `planner/service.py` stores date-specific load forecasts.
- `planner/shadow.py` passes the selected profile into the rolling optimizer and records the selection in each output record.
- `docs/daily-report-operations.md` documents maintenance of `hourly_report_inputs`.
- `docs/daily_report/battery-flow-pnl.md` documents the household-load energy balance.
