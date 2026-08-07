# Old GUI shared system configuration

## Purpose

The old GUI reads installation-wide values from the same strict shared configuration as the new GUI. This removes runtime disagreement between the two interfaces while retaining the existing old-GUI configuration for web-specific behavior.

## Location

- Old GUI entry point: [`main/charge_schedule_mobile.php`](../../main/charge_schedule_mobile.php)
- Shared configuration: [`common/config/system.json`](../../common/config/system.json)
- Shared PHP loader: [`common/php/system_config.php`](../../common/php/system_config.php)
- Old-GUI web configuration: [`main/config/config.json`](../../main/config/config.json)
- Integration tests: [`tools/tests/test_old_gui_system_config.py`](../../tools/tests/test_old_gui_system_config.py)
- Backend and fallback tests: [`tools/tests/test_old_gui_backend_system_config.py`](../../tools/tests/test_old_gui_backend_system_config.py)

## Inputs and outputs

The old GUI obtains these values from the shared configuration:

- Battery capacity.
- Battery forecast efficiency.
- Minimum charge percentage.
- Maximum charge percentage.
- Signed schedule power range and power step.
- Installation timezone.
- Supplier markup, energy tax, VAT multiplier and price precisions.
- Shortwave-radiation location and timezone.

It continues to obtain these values from the old-GUI web configuration:

- Browser-facing API URLs.
- Price overview display and reference settings.
- Schedule-condition and other old-GUI-only policies.

The page injects the validated values into the same JavaScript names used before this migration, so its browser modules do not require a second migration.

## Flow and behavior

1. The old GUI authenticates the request.
2. It loads and strictly validates `common/config/system.json` through the common PHP loader.
3. It loads the old-GUI web configuration through `ConfigLoader`.
4. It sets PHP's timezone from `installation.timezone` in the shared configuration.
5. It renders the page with shared battery, forecast, schedule and price-conversion values plus web-specific values from the old configuration.
6. The energy-graph proxy uses shared battery capacity and installation timezone while retaining its web-only upstream URL and cache policy.
7. The shortwave endpoint defaults to the shared installation coordinates and timezone; explicit API query parameters may still request another location.
8. Schedule resolver and legacy schedule endpoints use the shared installation timezone.
9. Browser modules require the injected shared battery, efficiency, schedule-range, schedule-step and price-conversion values and no longer contain duplicated operational defaults.
10. The PHP target-battery planner reads the shared forecast profile, efficiency, schedule range and schedule step.

The configuration editor still writes `main/config/config.json` and now only contains web-only keys. Shared battery, installation and price-conversion values are edited in `common/config/system.json`. A future settings API should make deliberate, validated and persistent writes to the shared file; that write path is outside this phase.

## Edge cases and failure modes

- Invalid or missing shared configuration stops the old GUI and displays a configuration error instead of using fallback battery values.
- Invalid old-GUI web configuration also stops the page and is identified separately in the error message.
- If both configurations fail, then both errors appear in the startup error page.
- The timezone temporarily falls back to UTC only while constructing the error response when shared configuration cannot be loaded; no operational page is rendered in that state.
- The active old-GUI page and its shared-setting PHP consumers are migrated. Raspberry Pi automation still reads its own configuration.
- The old configuration editor only alters web-only keys. Shared operational values are owned by `common/config/system.json`.
- Invalid shared configuration makes migrated JSON endpoints fail closed instead of returning data calculated with old capacity, location or conversion defaults.
- Cached energy payloads keep their measured history, but the response capacity is replaced with the current shared capacity before rendering percentages.

## Related files

- [`common/config/system-configuration.md`](../common/config/system-configuration.md): canonical contract and cross-consumer status.
- [`app/shared-system-configuration.md`](../app/shared-system-configuration.md): equivalent new-GUI integration.
- [`main/includes/config_loader.php`](../../main/includes/config_loader.php): remaining web-specific configuration loader.
- [`main/includes/price_conversion.php`](../../main/includes/price_conversion.php): common-backed helper used by price and report consumers.
- [`main/api/energy_graph_proxy.php`](../../main/api/energy_graph_proxy.php): common-backed capacity and timezone consumer.
- [`main/api/shortwave_radiation_api.php`](../../main/api/shortwave_radiation_api.php): common-backed default solar location consumer.
- [`automate/config/config.jsonc`](../../automate/config/config.jsonc): current automation configuration source.
