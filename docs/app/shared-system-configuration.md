# New GUI shared system configuration

## Purpose

The production Graphite Signal Dark GUI and its supporting PHP services read shared battery, installation and price-conversion values through the strict common PHP loader.

The temporary frontend shadow-comparison phase was intentionally skipped before production because the new GUI's existing shared values already matched the common configuration. The supporting backend consumers were migrated separately with focused regression and malformed-configuration tests.

## Location

- New GUI entry point: [`app/index.php`](../../app/index.php)
- Common PHP loader: [`common/php/system_config.php`](../../common/php/system_config.php)
- Common configuration: [`common/config/system.json`](../../common/config/system.json)
- Common contract: [`system-configuration.md`](../common/config/system-configuration.md)
- Integration tests: [`tools/tests/test_new_gui_system_config.py`](../../tools/tests/test_new_gui_system_config.py)
- Backend integration tests: [`tools/tests/test_new_gui_backend_system_config.py`](../../tools/tests/test_new_gui_backend_system_config.py)

## Inputs and outputs

### Common inputs

The new GUI obtains these values from `common/config/system.json`:

- Minimum state of charge.
- Maximum state of charge.
- Nominal battery capacity.
- Installation name.
- Latitude and longitude.
- Timezone.
- Price-conversion values and precision.

### Existing web inputs

The new GUI continues to obtain these values from `main/config/config.json` through `ConfigLoader`:

- Signed interface and schedule-editor power range.
- Schedule API URL.
- Price API URLs.
- Other deployment-specific web endpoints.

### Browser output

`app/index.php` injects the combined result into `window.GRAPHITE_APP_CONFIG`. Browser JavaScript therefore receives one object even though the values have two clearly separated server-side owners.

### Supporting backend inputs

The PHP services called by the new GUI now obtain these shared values from `system.json`:

- Schedule condition resolver: installation latitude, longitude and timezone.
- Price endpoints: supplier markup, energy tax, VAT multiplier and conversion precision.
- Energy-history endpoint: installation timezone and battery capacity.
- Daily-report helpers used by live energy history: installation timezone, including the explicit timezone argument passed to Python report generators.

The schedule data endpoint retains `include_conditions` in `main/config/config.json`. API URLs, signed editor power limits and Dutch electricity-market timezone rules also remain outside shared system configuration because they represent web deployment or market policy rather than installation facts.

## Flow and behaviour

1. Authentication completes through the existing login validator.
2. The strict common PHP loader reads and validates `system.json`.
3. The existing `ConfigLoader` reads web-specific configuration.
4. The common timezone becomes the PHP request timezone.
5. Sunrise and sunset events are calculated using the common installation coordinates and timezone.
6. Shared and web-specific values are combined into `GRAPHITE_APP_CONFIG`.
7. The browser components use the injected values as before.
8. Schedule requests resolve sunrise and sunset conditions with the common installation values.
9. Price requests use the common price-conversion values.
10. Energy-history requests use common capacity and timezone values through the daily-report backend.

No new write path is introduced. The new GUI remains a read-only consumer of common configuration.

## Error handling

- When the common configuration is missing or invalid, then the existing configuration-error panel is displayed.
- When the web configuration is invalid, then the same panel displays the web configuration error.
- When both fail, then both labelled errors are displayed.
- When common configuration fails, then shared browser values are `null` and solar events are empty, but normal application components are not rendered.
- When configuration fails before a timezone is available, then the error page uses UTC rather than an installation-specific fallback.
- When a migrated JSON endpoint encounters malformed shared configuration, then it returns an error and does not use the former duplicated location, capacity or conversion values.
- When server-side price conversion is invoked with malformed shared configuration, then the strict loader rejects the operation rather than calculating with embedded defaults.

The GUI does not silently return to the former 20%/90%, 5760 Wh, VAT 1.21 or hard-coded-location defaults. Those duplicated shared-value fallbacks have been removed from the new GUI JavaScript.

## Edge cases and failure modes

- When `system.json` is synchronized incompletely, then the strict loader rejects it and the GUI shows a configuration error.
- When the configured timezone is unsupported, then the common loader rejects it before solar calculations run.
- When web power limits differ from automation command caps, then the GUI continues to expose its existing web/editor range; this migration does not alter that behaviour.
- When JavaScript is reused without the PHP entry point, then battery and price components report missing required shared settings instead of operating with embedded shared-value defaults.
- When the common configuration changes, then the next PHP page request reads the new values because the loader does not cache across requests.

## Verification

Run the focused loader and GUI integration tests with:

```sh
python -m pytest -q \
  tools/tests/test_system_config_loaders.py \
  tools/tests/test_new_gui_system_config.py \
  tools/tests/test_new_gui_backend_system_config.py \
  tools/tests/test_price_conversion.py \
  tools/tests/test_app_energy_history.py
```

The frontend integration test renders the authenticated PHP entry point when a local authentication fixture is available. The backend tests verify source ownership, canonical values, schedule solar resolution, explicit report timezone propagation and strict malformed-configuration behavior.

## Related files

- [`app/assets/js/current-energy-status.js`](../../app/assets/js/current-energy-status.js): battery and live-status consumer.
- [`app/assets/js/price-plan.js`](../../app/assets/js/price-plan.js): price conversion and solar-marker consumer.
- [`main/includes/config_loader.php`](../../main/includes/config_loader.php): existing web configuration loader.
- [`main/data/resolve_schedule_conditions.php`](../../main/data/resolve_schedule_conditions.php): shared-location schedule condition resolver.
- [`main/includes/price_conversion.php`](../../main/includes/price_conversion.php): shared server-side price conversion.
- [`main/api/app_energy_history.php`](../../main/api/app_energy_history.php): shared-capacity and timezone history endpoint.
- [`daily_report/includes/report_api_common.php`](../../daily_report/includes/report_api_common.php): shared daily-report configuration and timezone owner.
- [`configuration-target-values.md`](../configuration-target-values.md): approved ownership and target values.
