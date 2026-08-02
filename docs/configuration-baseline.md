# Configuration consolidation: Phase 1 baseline

## Purpose

This document records the configuration state before any consolidation work begins. It is the Phase 1 baseline for moving shared system values toward a common configuration source without changing runtime behaviour.

The intended values and ownership decisions derived from this baseline are recorded in [`configuration-target-values.md`](configuration-target-values.md).

The baseline distinguishes:

- Values that describe the physical installation or shared business rules.
- Values that belong only to the web application.
- Values that belong only to the Raspberry Pi automation runtime.
- Presentation constants that should remain interface-specific.
- Current differences that must be resolved deliberately rather than hidden during migration.

Future configuration-writing API design is explicitly out of scope for this phase. The agreed direction is that a future change made through an authorized old or new interface will be authoritative and persistent, but Phase 1 only records current behaviour.

## Location

The current configuration is distributed across:

- [`main/config/config.json`](../main/config/config.json): primary PHP/web configuration.
- [`main/includes/config_loader.php`](../main/includes/config_loader.php): shared PHP loader for the main configuration.
- [`automate/config/config.jsonc`](../automate/config/config.jsonc): Raspberry Pi automation configuration.
- [`automate/config_loader.py`](../automate/config_loader.py): JSON-with-comments loader used by automation.
- [`app/index.php`](../app/index.php): new-interface server-side defaults and injected browser configuration.
- [`app/assets/js/current-energy-status.js`](../app/assets/js/current-energy-status.js): new-interface client-side fallbacks and status policies.
- [`app/assets/js/power-bar-scale.js`](../app/assets/js/power-bar-scale.js): new-interface nonlinear power scale.
- [`app/assets/js/battery-color-scale.js`](../app/assets/js/battery-color-scale.js): new-interface battery colour scale.
- [`app/assets/js/grid-exchange-color-scale.js`](../app/assets/js/grid-exchange-color-scale.js): new-interface grid colour thresholds.
- [`main/data/resolve_schedule_conditions.php`](../main/data/resolve_schedule_conditions.php): old-interface schedule condition and sun-event resolution.
- [`main/includes/price_conversion.php`](../main/includes/price_conversion.php): shared PHP price-conversion defaults and calculations.
- [`pathlab/api/path_data.php`](../pathlab/api/path_data.php): path projection defaults and calculations.

Baseline capture:

- Date: 2026-08-02, Europe/Amsterdam.
- Git revision: `510f25d236b41f18ea6cae10b5bc1fd3b311dd80`.
- The working tree contained pre-existing changes in new-interface files when this baseline was captured.
- No live Raspberry Pi API response was captured during this phase; effective runtime values must therefore be verified separately.
- Secrets, device identifiers, private addresses, database credentials and tokens are intentionally excluded.

## Inputs and outputs

### Primary PHP configuration input

`ConfigLoader` currently checks these paths in order:

1. `main/config/config.json`
2. `main/run_schedule/config/config.json`

The first existing file is decoded and retained in memory for the PHP request. Missing keys return a caller-provided fallback. The loader checks JSON syntax but does not currently validate a configuration schema or cross-field rules.

### Primary automation configuration input

The automation controller reads `automate/config/config.jsonc`. Comments are stripped by the Python loader. The controller parses and normalizes selected values during initialization.

### Browser configuration output

The old and new PHP interfaces inject selected PHP configuration values into JavaScript. JavaScript modules then apply additional fallbacks and clamping.

### Automation output

The automation controller uses its loaded minimum and maximum state-of-charge values to prevent charging or discharging at its active limits. Its API can also modify those controller attributes at runtime.

## Shared-setting baseline

### Minimum state of charge

- `main/config/config.json`: `MIN_CHARGE_LEVEL = 15`.
- `automate/config/config.jsonc`: `MIN_CHARGE_LEVEL = 15`.
- New-interface PHP fallback: 20%.
- New-interface JavaScript fallback: 20%.
- Old-interface PHP fallback: 20%.
- Path Lab fallback: 15%.
- Automation legacy fallback: 20%.
- Agreed future common value: 15%.

Current consumers include:

- The new-interface battery target, marker and time-to-limit calculations.
- The old-interface battery summaries, schedule estimates and pack calculations.
- Path Lab projections.
- The automation controller's discharge protection.
- Automation runtime limit endpoints.

### Maximum state of charge

- `main/config/config.json`: `MAX_CHARGE_LEVEL = 91`.
- `automate/config/config.jsonc`: `MAX_CHARGE_LEVEL = 93`.
- New-interface PHP fallback: 90%.
- New-interface JavaScript fallback: 90%.
- Old-interface PHP fallback: 90%.
- Path Lab fallback: 96%.
- Automation legacy fallback: 90%.
- Agreed future common and authoritative value: 91%.

This is the main confirmed configuration discrepancy. The web calculations currently use 91%, while a freshly started automation controller reads 93% from its own file unless a runtime change is applied.

### Battery capacity

- `main/config/config.json`: `baseWh = 5760` Wh.
- Common PHP and JavaScript fallback: 5760 Wh.
- The automation configuration does not currently define the same capacity key.

Current consumers include:

- New-interface stored-energy and one-hour schedule estimates.
- Old-interface battery capacity and schedule estimates.
- Energy history and graph percentage calculations.
- Path Lab projections.

The value is a strong common-configuration candidate for web calculations. Whether automation needs the same physical-capacity value must be confirmed before adding it as an automation dependency.

### Web power range

- `main/config/config.json`: `minGridPower = -1600` W.
- `main/config/config.json`: `maxGridPower = 1600` W.
- New- and old-interface fallbacks: -1200 W and 1200 W.

Current consumers include:

- New-interface power and grid axes.
- New-interface fixed-power and net-zero limit editors.
- Old-interface grid visualizations.
- Old rule-editor bounds.

These values are currently named as grid limits but are also used as schedule editor limits. Their intended meaning must be clarified before consolidation.

### Automation command limits

- `automate/config/config.jsonc`: `MAX_DISCHARGE_POWER = 1200` W.
- `automate/config/config.jsonc`: `MAX_CHARGE_POWER = 1200` W.
- Automation legacy fallbacks: 1000 W discharge and 1200 W charge.

The controller clamps outgoing battery commands to these values. They are not currently equal to the web power range of -1600 W to 1600 W.

Before consolidation, decide whether:

- The web range is only a display/editor range and the automation values are independent actuator limits.
- Or all four values are intended to describe one physical battery power envelope.

They must not be merged solely because their units are watts.

### Location and timezone

Primary web values:

- Name: Amsterdam.
- Latitude: 52.3676.
- Longitude: 4.9041.
- Timezone: `Europe/Amsterdam`.

Current copies and fallbacks:

- `main/config/config.json` contains latitude 52.3676 and longitude 4.9041.
- The new interface hard-codes the same name, coordinates and timezone in `APP_SOLAR_LOCATION`.
- The schedule resolver falls back to latitude 52.3676 and longitude 4.9041.
- The standalone shortwave endpoint falls back to latitude 52.3 and longitude 4.863.
- Path Lab shortwave calculations also use latitude 52.3 and longitude 4.863.
- Several PHP modules hard-code `Europe/Amsterdam` rather than reading it from configuration.

The two coordinate pairs are a confirmed discrepancy. They may represent intentional locations, but that intent is not documented in the source.

### Price conversion

`main/config/config.json` currently defines:

- Supplier markup: 0.0219 EUR/kWh.
- Energy tax: 0.0898 EUR/kWh.
- VAT multiplier: 1.21.
- Consumer-price precision: 4 decimal places.
- Spot-price precision: 6 decimal places.

`main/includes/price_conversion.php` contains identical fallbacks.

Current consumers include:

- Old-interface price displays.
- New-interface spot-price derivation.
- Daily report desktop and mobile pages.
- Shared PHP price calculations.

This group is already relatively centralized on the PHP side and is a strong common-configuration candidate.

## Current interface and runtime policies

### New-interface status policies

- Status refresh interval: 20 seconds.
- Stale-data threshold: 90 seconds.
- Individual status request timeout: 8 seconds.
- Energy-history query: three previous days plus today.
- Energy-history server maximum: 30 previous days.

These are interface or service policies rather than physical installation facts.

### New-interface nonlinear power scale

The current actual-to-displayed percentage anchors are:

- 0% actual to 0% displayed.
- 1% actual to 10% displayed.
- 5% actual to 20% displayed.
- 10% actual to 25% displayed.
- 20% actual to 30% displayed.
- 30% actual to 40% displayed.
- 50% actual to 50% displayed.
- 75% actual to 75% displayed.
- 100% actual to 100% displayed.

This is presentation configuration and should not become an automation dependency.

### New-interface battery colours

- 0% and 20%: `#ff625f`.
- 30%: `#f2a84a`.
- 40%: `#c5ca62`.
- 50%: `#9ed17a`.
- 60% and 100%: `#79d484`.

Intermediate colours are interpolated. The scale is independent of the configured battery safety limits.

### New-interface grid presentation

- Export colour boundary: below -10 W.
- Balance point: 0 W.
- Import colour boundary: above 10 W.
- Textual balanced range: -25 W through 25 W.

The colour boundary and textual balanced range intentionally or accidentally use different thresholds. This should be reviewed as an interface policy, not folded into the automation configuration.

### Old-interface calculation fallbacks

The old price overview currently falls back to:

- Missing-price proxy: 0.24.
- Popup power efficiency: 0.9.
- Net-zero reference power: 200 W.
- Net-zero-minus reference power: -180 W.
- Net-zero-plus reference power: 300 W.
- Hourly reference ranges: empty object.

These keys are absent from the current main JSON file, so the fallbacks are active.

### Automation-only baseline

The non-secret automation configuration currently includes:

- Test mode: enabled.
- Log level: INFO.
- Slow-charge start level: 80%.
- Slow-charge maximum power: 200 W.
- Minimum absolute power threshold: 30 W.
- Minimum applied power delta: 50 W.
- Maximum power delta per step: 300 W.
- Reversal ramp: enabled.
- Reversal ramp divisor: 2.
- Reversal minimum absolute power: 30 W.
- Main loop interval: 20 seconds.
- External API refresh interval: 60 seconds.
- Net-zero target: -10 W.
- Standby zero-count threshold: 300 readings.
- Status retention: 7 days.

These settings affect controller behaviour and should remain automation-owned unless a later design explicitly establishes shared semantics.

## Current mutation behaviour

### Main configuration editor

`main/config/index.php` can rewrite `main/config/config.json` persistently. It parses submitted values based on the type of each existing JSON value. It does not currently apply a schema, perform cross-field safety validation, update the automation file, or confirm Raspberry Pi synchronization.

### Automation runtime endpoints

The automation API exposes GET and POST operations for minimum and maximum charge levels. POST currently changes controller attributes in the running process and normalizes the pair to remain within 0% to 100%.

No write to `automate/config/config.jsonc` or `main/config/config.json` occurs in the inspected handlers. Therefore, the current API behaviour is runtime-only and is not a persistent, project-wide authoritative update path.

Future persistent editing from either interface remains deferred and must not be inferred from Phase 1.

## Flow and behaviour

### Current web flow

1. A PHP request loads `main/config/config.json` through `ConfigLoader`.
2. Missing keys use different caller-specific fallbacks.
3. PHP calculations use the resolved values directly.
4. Interface entry points inject selected values into JavaScript.
5. JavaScript may apply another fallback or clamp.

### Current automation flow

1. The controller loads `automate/config/config.jsonc` during initialization.
2. It parses charge and power limits with legacy fallbacks.
3. State-of-charge values are clamped to 0% through 100%.
4. If minimum exceeds maximum, maximum is raised to minimum.
5. Outgoing charge and discharge commands are blocked or clamped using the active controller values.
6. Runtime API calls can replace the in-memory state-of-charge limits until the process is restarted or changed again.

### Intended Phase 1 output

This document is the only Phase 1 deliverable. It does not introduce a common file, loader, schema, API or runtime switch.

## Edge cases and failure modes

- When a key is missing, different consumers can silently select different fallbacks.
- When PHP and automation configuration disagree, the interfaces can calculate a different target from the controller's enforced target.
- When the old configuration editor changes the main JSON, automation does not automatically persist the same value in its JSONC file.
- When a runtime limit endpoint is used, the active automation value can differ from both persistent files.
- When synchronization is delayed, the web host and Raspberry Pi can temporarily read different file revisions.
- When a JSON file is malformed, PHP reports a load error, but individual callers can still receive their local fallbacks.
- When automation configuration is malformed or missing, its loader and controller fallbacks determine whether startup continues.
- When similarly named watt values have different meanings, consolidating them can inadvertently weaken a controller limit or restrict a valid display range.
- When the shortwave coordinate pair differs from the schedule and new-interface pair, solar calculations can refer to different locations.
- When configuration files containing network or device details are documented, secrets or private infrastructure can be exposed; this baseline intentionally omits them.

## Phase 1 verification checklist

Before Phase 2 begins, capture and retain the following evidence from the actual Raspberry Pi and deployed web application:

1. Record the deployed Git revision on the web host and Raspberry Pi.
2. Record the loaded automation configuration path at process startup.
3. Record the startup-reported minimum and maximum charge levels.
4. Query the automation minimum and maximum endpoints and record their effective values.
5. Confirm whether a runtime charge-limit change survives an automation process restart.
6. Record the old interface's displayed minimum, maximum, capacity and power bounds.
7. Record the new interface's displayed minimum, maximum, capacity and power bounds.
8. Record a representative schedule estimate from both interfaces.
9. Record sunrise and sunset output from the new interface and schedule resolver for the same date.
10. Confirm the project synchronization direction and typical propagation delay.
11. Confirm which machine is allowed to write synchronized project files.
12. Preserve screenshots or JSON responses without credentials or tokens.

Example read-only runtime queries, using the configured automation base URL:

```sh
curl -s '<automation-base-url>/api/min_charge_level'
curl -s '<automation-base-url>/api/max_charge_level'
```

Do not perform POST requests as part of the Phase 1 baseline capture.

## Decisions recorded

- The future common maximum charge value is 91%.
- A future interface-originated parameter change must be authoritative for all consumers and persistent.
- Design and implementation of that write API are deferred.
- The Raspberry Pi has access to the synchronized project directory.
- Shared physical/system values should eventually have one source of truth.
- Interface presentation settings and automation-only control policies should remain separately owned unless their semantics are deliberately unified.

## Open questions for the next phase

Phase 2 resolved the value and ownership questions needed for read-only common configuration work. See [`configuration-target-values.md`](configuration-target-values.md). The following questions remain useful as historical baseline concerns or deferred behavioural decisions:

- Are `minGridPower` and `maxGridPower` display ranges, schedule bounds, physical battery limits, or a combination?
- Should the automation command caps remain 1200 W when web editors allow values up to 1600 W?
- Which coordinate pair is correct for shortwave radiation: 52.3676/4.9041 or 52.3/4.863?
- Does automation need the shared 5760 Wh capacity for any controller decision?
- Which synchronized host will be the single persistent configuration writer?
- What is the expected synchronization delay and conflict policy?
- Which existing runtime parameters will eventually become persistently editable from an interface?

## Related files

- [`main/config/index.php`](../main/config/index.php): current persistent main-configuration editor.
- [`main/charge_schedule_mobile.php`](../main/charge_schedule_mobile.php): old-interface configuration injection.
- [`main/assets/js/price_overview_bar.js`](../main/assets/js/price_overview_bar.js): old price and battery estimate policies.
- [`main/assets/js/schedule_renderer.js`](../main/assets/js/schedule_renderer.js): old battery and grid calculations.
- [`automate/device_controller.py`](../automate/device_controller.py): active battery safety and power limits.
- [`automate/automate_api.py`](../automate/automate_api.py): current runtime limit endpoints.
- [`automate/control/commands.php`](../automate/control/commands.php): PHP definitions for automation commands.
- [`main/api/app_energy_history.php`](../main/api/app_energy_history.php): history API capacity output.
- [`main/includes/app_energy_history.php`](../main/includes/app_energy_history.php): history period and timezone constants.
- [`main/api/shortwave_radiation_api.php`](../main/api/shortwave_radiation_api.php): standalone shortwave location defaults.
- [`daily_report/index.php`](../daily_report/index.php): daily report price-conversion consumer.
