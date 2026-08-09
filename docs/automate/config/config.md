# Automation-local configuration

## Purpose

`automate/config/config.jsonc` contains deployment-local connection and operational settings for Raspberry Pi automation. It is intentionally excluded from Git because it can contain installation-specific device addresses and identifiers.

Shared physical and installation settings do not belong in this file. Their canonical source and complete contract are documented in [`common/config/system-configuration.md`](../../common/config/system-configuration.md).

## Location

- Deployment-local configuration: `automate/config/config.jsonc`.
- Source-side detailed key reference: [`automate/config/config.md`](../../../automate/config/config.md).
- Shared system configuration: [`common/config/system.json`](../../../common/config/system.json).
- Automation loader: [`automate/config_loader.py`](../../../automate/config_loader.py).

## Inputs and outputs

The local JSONC file supplies device and meter connections, test and logging controls, schedule/API locations, loop intervals, retention, runtime-control tuning and optional dynamic slow-charge behavior.

The shared JSON file supplies:

- Minimum and maximum battery state of charge.
- Maximum charge and discharge command magnitudes.
- Installation timezone used for schedules, status timestamps and energy grouping.

The controller exposes the validated shared values as `min_charge_level`, `max_charge_level`, `max_charge_power`, `max_discharge_power` and `timezone`.

## Flow and behaviour

1. Automation loads and parses its deployment-local JSONC file.
2. It loads `common/config/system.json` through the strict common Python loader.
3. Invalid shared configuration stops initialization; no local battery fallback is applied.
4. Startup logging prints both resolved configuration paths and the effective battery limits and caps.
5. A change to shared configuration takes effect after automation is restarted.

The following legacy keys must not be present in `automate/config/config.jsonc`:

- `MIN_CHARGE_LEVEL`
- `MAX_CHARGE_LEVEL`
- `MAX_CHARGE_POWER`
- `MAX_DISCHARGE_POWER`

Automation-only settings such as `TEST_MODE`, `NETZERO_TARGET_W`, slow-charge tapering, command-shaping thresholds and loop timing remain local.

## Edge cases and failure modes

- A synchronized but stale `system.json` can leave the Raspberry Pi on older values until the next sync and restart.
- Malformed or invalid shared JSON prevents startup instead of silently substituting safety limits.
- Changing a shared value does not hot-reload an already-running automation process.
- GET requests to the charge-limit API expose the active shared values; POST requests return HTTP 405 and cannot create temporary overrides.
- Persistent editing of shared values through either GUI remains deferred until a dedicated authenticated write API is designed.

## Related files

- [`device-controller.md`](../device-controller.md)
- [`automate-www.md`](../automate-www.md)
- [`automate-overview.md`](../automate-overview.md)
- [`common/config/system-configuration.md`](../../common/config/system-configuration.md)
