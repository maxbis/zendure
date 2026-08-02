# Shared system configuration

## Purpose

`system.json` is the future canonical, non-secret source for installation facts that must eventually agree across the old interface, new interface, shared PHP calculations and Raspberry Pi automation.

Phase 3 introduced the file and schema. Phase 4 added independently tested PHP and Python readers. The new GUI is now the first runtime consumer through the PHP reader; the old GUI and automation still use their existing configuration sources.

## Location

- Configuration: [`common/config/system.json`](../../../common/config/system.json)
- JSON Schema: [`common/config/system.schema.json`](../../../common/config/system.schema.json)
- Direct Apache access restriction: [`common/config/.htaccess`](../../../common/config/.htaccess)
- PHP loader: [`common/php/system_config.php`](../../../common/php/system_config.php)
- Python loader: [`common/python/system_config.py`](../../../common/python/system_config.py)
- Cross-language tests: [`tools/tests/test_system_config_loaders.py`](../../../tools/tests/test_system_config_loaders.py)
- Phase 1 baseline: [`configuration-baseline.md`](../../configuration-baseline.md)
- Phase 2 decisions: [`configuration-target-values.md`](../../configuration-target-values.md)

The file is inside the synchronized project so the web host and Raspberry Pi can eventually read the same relative project resource.

## Inputs and outputs

### Configuration input

The configuration contains:

- Contract version: 1.
- Nominal battery capacity: 5760 Wh.
- Minimum state of charge: 15%.
- Maximum state of charge: 91%.
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

Neither loader caches the result. The new GUI now uses the PHP loader and injects its validated shared values into browser JavaScript. The Python loader remains unused by production automation.

## Contract

### Root object

Required properties:

- `schemaVersion`
- `battery`
- `installation`
- `priceConversion`

Unknown root properties are rejected by the schema.

### Battery

Required properties:

- `capacityWh`: positive integer.
- `minChargePercent`: integer from 0 through 99.
- `maxChargePercent`: integer from 1 through 100.

The portable JSON Schema validates each range independently. Both Phase 4 loaders additionally enforce `minChargePercent < maxChargePercent`, because draft 2020-12 JSON Schema cannot portably compare two sibling numeric properties.

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

Current flow after new-GUI integration:

1. `system.json` records the approved Phase 2 values.
2. `system.schema.json` defines its structural contract.
3. The new GUI loads shared system values through the common PHP loader.
4. Existing automation continues loading `automate/config/config.jsonc`.
5. The old GUI and shared PHP backend calculations continue loading `main/config/config.json`.
6. The Python loader remains available but unused by automation.
7. Parity tests confirm both languages return the same normalized values and reject the same invalid categories.

Run the focused Phase 4 tests with:

```sh
python -m pytest -q tools/tests/test_system_config_loaders.py
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
- When the timezone is non-empty but invalid, then schema validation may pass, but both loaders reject it.
- When the synchronized file is stale on one host, then valid JSON does not prove both hosts use the same version.
- When direct web access is not blocked by the active web server, then the current non-secret file could be downloadable; secrets must never be added.
- When a consumer switches to this file prematurely, then the automation maximum changes from its current persistent 93% to the intended 91%.

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
- The new GUI may use the PHP loader, while the old GUI and automation remain on their existing sources until their own migration phases.

## Related files

- [`main/includes/config_loader.php`](../../../main/includes/config_loader.php): current PHP configuration loader.
- [`automate/config_loader.py`](../../../automate/config_loader.py): current automation JSONC loader.
- [`main/config/config.json`](../../../main/config/config.json): current web configuration source.
- [`automate/config/config.jsonc`](../../../automate/config/config.jsonc): current automation source.
- [`main/includes/price_conversion.php`](../../../main/includes/price_conversion.php): current price-conversion consumer.
- [`app/index.php`](../../../app/index.php): current new-interface configuration injection.
- [`common/php/system_config.php`](../../../common/php/system_config.php): strict PHP reader.
- [`common/python/system_config.py`](../../../common/python/system_config.py): strict Python reader.
- [`app/shared-system-configuration.md`](../../app/shared-system-configuration.md): new-GUI integration and ownership boundary.
