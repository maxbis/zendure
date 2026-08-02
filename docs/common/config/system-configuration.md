# Shared system configuration

## Purpose

`system.json` is the future canonical, non-secret source for installation facts that must eventually agree across the old interface, new interface, shared PHP calculations and Raspberry Pi automation.

Phase 3 only introduces the file and its schema. No runtime consumer loads it yet, so adding it does not change current application or controller behaviour.

## Location

- Configuration: [`common/config/system.json`](../../../common/config/system.json)
- JSON Schema: [`common/config/system.schema.json`](../../../common/config/system.schema.json)
- Direct Apache access restriction: [`common/config/.htaccess`](../../../common/config/.htaccess)
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

### Intended future output

Future PHP and Python loaders will return a validated configuration object. Phase 3 does not provide those loaders and does not inject this file into browser JavaScript.

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

The portable JSON Schema validates each range independently. The future loaders must additionally enforce `minChargePercent < maxChargePercent`, because draft 2020-12 JSON Schema cannot portably compare two sibling numeric properties.

### Installation

Required properties:

- `name`: non-empty string.
- `latitude`: number from -90 through 90.
- `longitude`: number from -180 through 180.
- `timezone`: non-empty IANA timezone string.

The schema verifies that timezone is non-empty. Future loaders must verify that the named timezone is supported by the host runtime.

### Price conversion

Required properties:

- `supplierMarkupEurPerKwh`: non-negative number.
- `energyTaxEurPerKwh`: non-negative number.
- `vatMultiplier`: positive number.
- `consumerPrecision`: integer from 0 through 12.
- `spotPrecision`: integer from 0 through 12.

Unknown properties are rejected inside every section.

## Flow and behaviour

Current Phase 3 flow:

1. `system.json` records the approved Phase 2 values.
2. `system.schema.json` defines its structural contract.
3. Existing PHP code continues loading `main/config/config.json`.
4. Existing automation continues loading `automate/config/config.jsonc`.
5. Existing hard-coded and fallback values remain active.

Planned Phase 4 flow:

1. A PHP loader reads and validates `system.json` without replacing current consumers.
2. A Python loader reads and validates the same file without replacing automation's current configuration.
3. Both loaders enforce semantic checks not expressible in portable JSON Schema.
4. Parity tests confirm both languages return the same normalized values and errors.

## Security boundary

The shared system file contains no credentials, tokens, device identifiers or private endpoint addresses.

The `.htaccess` file denies direct HTTP access when Apache permits directory overrides. Filesystem access by PHP, Python and synchronization remains unaffected.

When the deployment uses Nginx or ignores `.htaccess`, then equivalent web-server configuration is still required before sensitive settings are ever added. Secrets must remain outside this file regardless of web-server protection.

## Edge cases and failure modes

- When `schemaVersion` is not 1, then a conforming future loader must reject the file.
- When a required property is absent, then schema validation fails.
- When an unknown property is present, then schema validation fails rather than silently ignoring a likely typo.
- When minimum is equal to or greater than maximum, then schema ranges alone may pass; future loader semantic validation must reject it.
- When the timezone is non-empty but invalid, then schema validation may pass; future runtime validation must reject it.
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

## Related files

- [`main/includes/config_loader.php`](../../../main/includes/config_loader.php): current PHP configuration loader.
- [`automate/config_loader.py`](../../../automate/config_loader.py): current automation JSONC loader.
- [`main/config/config.json`](../../../main/config/config.json): current web configuration source.
- [`automate/config/config.jsonc`](../../../automate/config/config.jsonc): current automation source.
- [`main/includes/price_conversion.php`](../../../main/includes/price_conversion.php): current price-conversion consumer.
- [`app/index.php`](../../../app/index.php): current new-interface configuration injection.
