# New GUI shared system configuration

## Purpose

The new Graphite Signal Dark GUI is the first runtime consumer of the common system configuration. It reads shared battery, installation and price-conversion values through the strict PHP loader.

The temporary shadow-comparison phase was intentionally skipped because the new GUI is not yet in production and its existing shared values already matched the common configuration.

## Location

- New GUI entry point: [`app/index.php`](../../app/index.php)
- Common PHP loader: [`common/php/system_config.php`](../../common/php/system_config.php)
- Common configuration: [`common/config/system.json`](../../common/config/system.json)
- Common contract: [`system-configuration.md`](../common/config/system-configuration.md)
- Integration tests: [`tools/tests/test_new_gui_system_config.py`](../../tools/tests/test_new_gui_system_config.py)

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

## Flow and behaviour

1. Authentication completes through the existing login validator.
2. The strict common PHP loader reads and validates `system.json`.
3. The existing `ConfigLoader` reads web-specific configuration.
4. The common timezone becomes the PHP request timezone.
5. Sunrise and sunset events are calculated using the common installation coordinates and timezone.
6. Shared and web-specific values are combined into `GRAPHITE_APP_CONFIG`.
7. The browser components use the injected values as before.

No new write path is introduced. The new GUI remains a read-only consumer of common configuration.

## Error handling

- When the common configuration is missing or invalid, then the existing configuration-error panel is displayed.
- When the web configuration is invalid, then the same panel displays the web configuration error.
- When both fail, then both labelled errors are displayed.
- When common configuration fails, then shared browser values are `null` and solar events are empty, but normal application components are not rendered.
- When configuration fails before a timezone is available, then the error page uses UTC rather than an installation-specific fallback.

The GUI does not silently return to the former 20%/90% or hard-coded-location defaults.

## Edge cases and failure modes

- When `system.json` is synchronized incompletely, then the strict loader rejects it and the GUI shows a configuration error.
- When the configured timezone is unsupported, then the common loader rejects it before solar calculations run.
- When web power limits differ from automation command caps, then the GUI continues to expose its existing web/editor range; this migration does not alter that behaviour.
- When JavaScript is reused without the PHP entry point, then its internal defensive defaults may still exist, but the authenticated GUI entry point only renders operational components after server configuration succeeds.
- When the common configuration changes, then the next PHP page request reads the new values because the loader does not cache across requests.

## Verification

Run the focused loader and GUI integration tests with:

```sh
python -m pytest -q \
  tools/tests/test_system_config_loaders.py \
  tools/tests/test_new_gui_system_config.py
```

The integration test renders the authenticated PHP entry point when a local authentication fixture is available and verifies that common values and web-specific values come from their intended sources.

## Related files

- [`app/assets/js/current-energy-status.js`](../../app/assets/js/current-energy-status.js): battery and live-status consumer.
- [`app/assets/js/price-plan.js`](../../app/assets/js/price-plan.js): price conversion and solar-marker consumer.
- [`main/includes/config_loader.php`](../../main/includes/config_loader.php): existing web configuration loader.
- [`configuration-target-values.md`](../configuration-target-values.md): approved ownership and target values.
