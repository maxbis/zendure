# Old GUI shared system configuration

## Purpose

The old GUI reads installation-wide values from the same strict shared configuration as the new GUI. This removes runtime disagreement between the two interfaces while retaining the existing old-GUI configuration for web-specific behavior.

## Location

- Old GUI entry point: [`main/charge_schedule_mobile.php`](../../main/charge_schedule_mobile.php)
- Shared configuration: [`common/config/system.json`](../../common/config/system.json)
- Shared PHP loader: [`common/php/system_config.php`](../../common/php/system_config.php)
- Old-GUI web configuration: [`main/config/config.json`](../../main/config/config.json)
- Integration tests: [`tools/tests/test_old_gui_system_config.py`](../../tools/tests/test_old_gui_system_config.py)

## Inputs and outputs

The old GUI obtains these values from the shared configuration:

- Battery capacity.
- Minimum charge percentage.
- Maximum charge percentage.
- Installation timezone.
- Supplier markup, energy tax, VAT multiplier and price precisions.

It continues to obtain these values from the old-GUI web configuration:

- Browser-facing API URLs.
- Minimum and maximum grid power used by the interface.
- Price overview display and reference settings.
- Schedule-condition and other old-GUI-only policies.

The page injects the validated values into the same JavaScript names used before this migration, so its browser modules do not require a second migration.

## Flow and behavior

1. The old GUI authenticates the request.
2. It loads and strictly validates `common/config/system.json` through the common PHP loader.
3. It loads the old-GUI web configuration through `ConfigLoader`.
4. It sets PHP's timezone from `installation.timezone` in the shared configuration.
5. It renders the page with shared battery and price-conversion values plus web-specific values from the old configuration.

The configuration editor still writes `main/config/config.json`. Its duplicated battery, installation and price-conversion fields are no longer authoritative for either GUI. Editing those duplicates does not change the shared runtime values. A future settings API should make deliberate, validated and persistent writes to the shared file; that write path is outside this phase.

## Edge cases and failure modes

- Invalid or missing shared configuration stops the old GUI and displays a configuration error instead of using fallback battery values.
- Invalid old-GUI web configuration also stops the page and is identified separately in the error message.
- If both configurations fail, then both errors appear in the startup error page.
- The timezone temporarily falls back to UTC only while constructing the error response when shared configuration cannot be loaded; no operational page is rendered in that state.
- The shared schedule condition resolver now uses common installation values. Other legacy PHP paths may still require a separate audit, and Raspberry Pi automation still reads its own configuration.
- The old configuration editor can still alter duplicated shared-looking fields, but those edits no longer affect the GUI. This is intentional until a shared write API is designed.

## Related files

- [`common/config/system-configuration.md`](../common/config/system-configuration.md): canonical contract and cross-consumer status.
- [`app/shared-system-configuration.md`](../app/shared-system-configuration.md): equivalent new-GUI integration.
- [`main/includes/config_loader.php`](../../main/includes/config_loader.php): remaining web-specific configuration loader.
- [`main/includes/price_conversion.php`](../../main/includes/price_conversion.php): common-backed helper used by price and report consumers.
- [`automate/config/config.jsonc`](../../automate/config/config.jsonc): current automation configuration source.
