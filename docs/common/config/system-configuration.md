# Shared system configuration

## Purpose

`system.json` is the canonical, non-secret source for system properties that must agree across the old interface, new interface, shared PHP calculations and Raspberry Pi automation.

Phase 3 introduced the file and schema. Phase 4 added independently tested PHP and Python readers. Both GUIs consume shared values through the PHP reader, and Raspberry Pi automation consumes its battery limits, power caps and installation timezone through the Python reader.

## Location

- Configuration: [`common/config/system.json`](../../../common/config/system.json)
- JSON Schema: [`common/config/system.schema.json`](../../../common/config/system.schema.json)
- Direct Apache access restriction: [`common/config/.htaccess`](../../../common/config/.htaccess)
- PHP loader: [`common/php/system_config.php`](../../../common/php/system_config.php)
- Python loader: [`common/python/system_config.py`](../../../common/python/system_config.py)
- Cross-language tests: [`tools/tests/test_system_config_loaders.py`](../../../tools/tests/test_system_config_loaders.py)
- Phase 1 baseline: [`configuration-baseline.md`](../../configuration-baseline.md)
- Phase 2 decisions: [`configuration-target-values.md`](../../configuration-target-values.md)

The file is inside the synchronized project so the web host and Raspberry Pi read the same relative project resource.

## Inputs and outputs

### Configuration input

The configuration contains:

- Contract version: 1.
- Nominal battery capacity: 5760 Wh.
- Minimum state of charge: 15%.
- Maximum state of charge: 91%.
- Battery forecast efficiency: 0.9.
- Maximum charge command magnitude: 1200 W.
- Maximum discharge command magnitude: 1200 W.
- Default 24-hour household-usage forecast: 100 W from 00:00 through 07:59, 220 W from 08:00 through 20:59 and 160 W from 21:00 through 23:59.
- Schedule range: -1600 through 1600 W.
- Schedule power step: 100 W.
- Installation: Amsterdam.
- Latitude: 52.3676.
- Longitude: 4.9041.
- Timezone: `Europe/Amsterdam`.
- Supplier markup: 0.0219 EUR/kWh.
- Energy tax: 0.0898 EUR/kWh.
- VAT multiplier: 1.21.
- Consumer precision: 4 decimal places.
- Spot precision: 6 decimal places.

### Loader output

The PHP `loadSystemConfig()` function and Python `load_system_config()` function return the same validated, normalized configuration structure. Both accept an optional file path for tests and otherwise locate `common/config/system.json` relative to their own source file.

The base loaders do not cache the result. Both GUIs and their migrated PHP backend services use the PHP loader; request-level helpers may retain the validated array for the duration of one PHP request. Automation loads the Python result once when each controller or timezone-dependent utility process starts.

## Contract

### Root object

Required properties:

- `schemaVersion`
- `battery`
- `forecast`
- `schedule`
- `installation`
- `priceConversion`

Unknown root properties are rejected by the schema.

### Battery

Required properties:

- `capacityWh`: positive integer.
- `minChargePercent`: integer from 0 through 99.
- `maxChargePercent`: integer from 1 through 100.
- `efficiency`: number greater than 0 and no greater than 1.
- `maxChargePowerW`: positive integer command magnitude.
- `maxDischargePowerW`: positive integer command magnitude.

The portable JSON Schema validates each range independently. Both Phase 4 loaders additionally enforce `minChargePercent < maxChargePercent`, because draft 2020-12 JSON Schema cannot portably compare two sibling numeric properties.

The power-cap properties are automation inputs. Outgoing battery commands are clamped to these shared positive magnitudes.

### Forecast

Required properties:

- `defaultHouseholdUsageWByHour`: exactly 24 non-negative integer watt values, indexed by local hour 0 through 23.

This fallback usage model is consumed by the new-GUI JavaScript forecast and the PHP target-battery planner.

### Schedule

Required properties:

- `minPowerW`: integer no greater than zero.
- `maxPowerW`: non-negative integer.
- `powerStepW`: positive integer.

Both loaders additionally require `minPowerW < maxPowerW`. The range describes schedule planning and editing; it remains distinct from the smaller battery command caps.

### Installation

Required properties:

- `name`: non-empty string.
- `latitude`: number from -90 through 90.
- `longitude`: number from -180 through 180.
- `timezone`: non-empty IANA timezone string.

The schema verifies that timezone is non-empty. The PHP loader checks `DateTimeZone::listIdentifiers()`, and the Python loader checks `zoneinfo.ZoneInfo`.

### Price conversion

Required properties:

- `supplierMarkupEurPerKwh`: non-negative number.
- `energyTaxEurPerKwh`: non-negative number.
- `vatMultiplier`: positive number.
- `consumerPrecision`: integer from 0 through 12.
- `spotPrecision`: integer from 0 through 12.

Unknown properties are rejected inside every section.

## Flow and behaviour

Current flow after the GUI and automation integrations:

1. `system.json` records the approved shared battery, forecast, schedule, installation and price-conversion values.
2. `system.schema.json` defines its structural contract.
3. The new GUI loads shared system values through the common PHP loader.
4. The old GUI loads shared battery, installation-timezone and price-conversion values through the same PHP loader.
5. The old GUI continues loading web-only settings from `main/config/config.json`.
6. Automation loads device, meter, loop and control-tuning settings from `automate/config/config.jsonc`.
7. Automation loads the battery minimum, battery maximum, charge cap, discharge cap and installation timezone from `system.json` through the strict Python loader.
8. Schedule solar resolution, PHP price conversion, energy history, the old energy graph and old-GUI shortwave defaults use shared values through the PHP loader.
9. Both GUIs and the PHP target-battery planner use the shared efficiency, forecast profile, schedule range and schedule power step.
10. Web-only routing and display policies remain in `main/config/config.json`. Shared battery, installation and price-conversion fields have been removed from that file.
11. Automation-local operational and connection settings remain in `automate/config/config.jsonc`. The migrated battery keys have been removed from that local file.
12. Parity, GUI and automation integration tests verify the ownership boundary and strict failure behavior.

Run the shared-loader and automation integration tests with:

```sh
pytest -q tools/tests/test_system_config_loaders.py tools/tests/automate/test_shared_system_config.py
```

## Security boundary

The shared system file contains no credentials, tokens, device identifiers or private endpoint addresses.

The `.htaccess` file denies direct HTTP access when Apache permits directory overrides. Filesystem access by PHP, Python and synchronization remains unaffected.

When the deployment uses Nginx or ignores `.htaccess`, then equivalent web-server configuration is still required before sensitive settings are ever added. Secrets must remain outside this file regardless of web-server protection.

## Edge cases and failure modes

- When `schemaVersion` is not 1, then a conforming future loader must reject the file.
- When a required property is absent, then schema validation fails.
- When an unknown property is present, then schema validation fails rather than silently ignoring a likely typo.
- When minimum is equal to or greater than maximum, then schema ranges alone may pass, but both loaders reject it.
- When the schedule minimum is equal to or greater than its maximum, then both loaders reject it.
- When the household-usage profile does not contain exactly 24 non-negative integer values, then both loaders reject it.
- When efficiency is zero, negative or greater than one, then both loaders reject it.
- When the timezone is non-empty but invalid, then schema validation may pass, but both loaders reject it.
- When the synchronized file is stale on one host, then valid JSON does not prove both hosts use the same version.
- When direct web access is not blocked by the active web server, then the current non-secret file could be downloadable; secrets must never be added.
- Automation now uses the shared 91% maximum instead of its former local 93% value. This is an intentional controller behavior change.
- Automation uses the shared 1200 W power caps, preserving its former command-cap behavior.
- When shared configuration is changed in `system.json`, then both GUIs pick up the new values through the PHP loader on their next request. Automation must be restarted to load the change.
- The old configuration editor only edits web-only keys in `main/config/config.json`. Persistent editing of shared settings through either GUI remains deferred.
- The automation API exposes the effective minimum and maximum through GET endpoints. POST requests to those endpoints return HTTP 405 so an in-memory override cannot conflict with the authoritative file.

## Phase 3 acceptance criteria

- Both JSON files parse successfully.
- The instance matches the structural schema.
- The schema rejects missing required properties, unknown properties and out-of-range primitive values.
- The approved Phase 2 values appear exactly once in the canonical instance.
- No PHP or Python runtime consumer references `common/config/system.json` yet.
- Existing interfaces and automation retain their current configuration sources.
- Direct Apache access to the new directory is denied without blocking filesystem reads.

## Phase 4 acceptance criteria

- PHP and Python locate the canonical file relative to their own source directories.
- Both return the approved configuration with matching keys and values.
- Both reject missing files and invalid JSON.
- Both reject missing and unknown properties.
- Both reject unsupported schema versions and invalid primitive ranges.
- Both reject minimum state of charge greater than or equal to maximum.
- Both reject an unrecognized timezone.
- Neither silently applies a fallback value.
- Both GUIs use the PHP loader.

## Automation migration acceptance criteria

- Automation resolves the common Python loader without relying on its working directory.
- Battery minimum, maximum and command caps come only from `system.json`.
- Schedule dates, status timestamps and hourly energy grouping use `installation.timezone`.
- Migrated keys are absent from `automate/config/config.jsonc`.
- Invalid shared configuration prevents controller startup instead of applying fallback safety values.
- Startup logging identifies both the automation-local and shared configuration files.
- Charge-limit GET endpoints report that their values are read-only and shared.
- Charge-limit POST endpoints cannot create a temporary override.
- Existing dynamic runtime controls such as `NETZERO_TARGET_W` remain unchanged.

## Related files

- [`main/includes/config_loader.php`](../../../main/includes/config_loader.php): old-GUI loader for remaining web-specific settings.
- [`automate/config_loader.py`](../../../automate/config_loader.py): automation JSONC loader and bridge to the strict common Python loader.
- [`main/config/config.json`](../../../main/config/config.json): web-only configuration source for routes, proxies and old-GUI display policies.
- `automate/config/config.jsonc`: deployment-local automation source for device, meter and operational settings; it is intentionally excluded from Git because it can contain installation-specific values.
- [`main/includes/price_conversion.php`](../../../main/includes/price_conversion.php): common-backed PHP price-conversion consumer.
- [`app/index.php`](../../../app/index.php): current new-interface configuration injection.
- [`common/php/system_config.php`](../../../common/php/system_config.php): strict PHP reader.
- [`common/python/system_config.py`](../../../common/python/system_config.py): strict Python reader.
- [`app/shared-system-configuration.md`](../../app/shared-system-configuration.md): new-GUI integration and ownership boundary.
- [`main/shared-system-configuration.md`](../../main/shared-system-configuration.md): old-GUI integration and ownership boundary.
