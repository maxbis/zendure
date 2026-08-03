# Configuration consolidation: Phase 2 target values

## Purpose

This document defines the intended values, names and meanings for the future configuration consolidation. It resolves the value decisions needed before a common configuration file and loaders are introduced.

Phase 2 is intentionally documentation-only. It does not change the old interface, new interface, automation controller, configuration files or runtime API.

The migration principle is:

- Preserve existing behaviour while configuration sources are consolidated.
- Resolve confirmed duplicate values now.
- Keep values separate when they have different meanings, even when they use the same unit.
- Treat later behavioural changes as separate, explicitly tested work.

## Location

This decision record is based on:

- [`configuration-baseline.md`](configuration-baseline.md): Phase 1 current-state inventory.
- [`main/config/config.json`](../main/config/config.json): current web values.
- [`automate/config/config.jsonc`](../automate/config/config.jsonc): current automation values.
- [`app/index.php`](../app/index.php): current new-interface injected configuration.
- [`main/charge_schedule_mobile.php`](../main/charge_schedule_mobile.php): current old-interface injected configuration.
- [`automate/device_controller.py`](../automate/device_controller.py): current controller enforcement.

Phase 3 introduced the read-only common file and schema described in [`common/config/system-configuration.md`](common/config/system-configuration.md). No runtime consumer uses them yet.

## Inputs and outputs

### Inputs

Phase 2 uses:

- Values observed in the Phase 1 baseline.
- The agreed persistent maximum state of charge of 91%.
- The requirement that future authorized interface changes are authoritative and persistent.
- The decision to defer the write API and persistent editing flow.
- The requirement to avoid changing controller behaviour during configuration restructuring.

### Output

The output is the approved set of target values and ownership boundaries implemented in the Phase 3 read-only common configuration contract.

## Canonical shared system values

### Battery identity and capacity

Intended canonical values:

- `battery.capacityWh`: 5760 Wh.

Meaning:

- This is the nominal total battery capacity used for energy and percentage calculations.
- It is not an efficiency-adjusted usable capacity.
- Consumers that need usable energy must apply their documented efficiency or state-of-charge window separately.

Initial consumers:

- Old-interface energy and schedule estimates.
- New-interface stored-energy and schedule estimates.
- Energy history and graph calculations.
- Path Lab projections.

Automation does not need to consume this value until a controller calculation explicitly requires capacity.

### Battery state-of-charge limits

Intended canonical values:

- `battery.minChargePercent`: 15%.
- `battery.maxChargePercent`: 91%.

Meaning:

- The minimum is the persistent lower state-of-charge boundary.
- The maximum is the persistent upper state-of-charge boundary.
- Both interfaces, schedule calculations, projections and automation must eventually use the same pair.
- The old 93% value in `automate/config/config.jsonc` is not an intended target value.

Validation requirements for the future common configuration:

- Both values must be numeric whole percentages.
- Both values must be between 0 and 100 inclusive.
- Minimum must be strictly lower than maximum.
- Invalid values must not be silently normalized into a different valid pair by the common loader.

Migration rule:

- When automation is eventually switched to the common file, changing its persistent maximum from 93% to 91% is a real controller behaviour change.
- That switch must be separately visible in the deployment plan and verified against the effective controller state.

### Installation location

Intended canonical values:

- `installation.name`: Amsterdam.
- `installation.latitude`: 52.3676.
- `installation.longitude`: 4.9041.
- `installation.timezone`: `Europe/Amsterdam`.

Meaning:

- The coordinates identify the installation location used for sunrise, sunset and solar forecast calculations.
- The timezone defines local schedule dates and hours.
- Sunrise/sunset rounding policies remain consumer behaviour and are not part of the coordinates themselves.

The less precise 52.3/4.863 coordinate pair historically used by the standalone shortwave endpoint and still used by Path Lab is considered a legacy fallback. The shortwave endpoint now defaults to the canonical installation location; Path Lab remains a separate future consumer.

### Price conversion

Intended canonical values:

- `priceConversion.supplierMarkupEurPerKwh`: 0.0219.
- `priceConversion.energyTaxEurPerKwh`: 0.0898.
- `priceConversion.vatMultiplier`: 1.21.
- `priceConversion.consumerPrecision`: 4.
- `priceConversion.spotPrecision`: 6.

Meaning:

- Supplier markup and energy tax are amounts in EUR/kWh.
- VAT is a multiplier rather than a percentage integer.
- Precision values control calculation rounding, not only visual formatting.

The existing conversion formula remains unchanged:

```text
consumer price = (spot price + supplier markup + energy tax) * VAT multiplier
```

## Power settings: deliberately separate meanings

Phase 2 does not merge the web's 1600 W values with automation's 1200 W values. Their current consumers show that they represent different responsibilities.

### Interface and schedule range

Intended migration values:

- `web.powerRange.minW`: -1600 W.
- `web.powerRange.maxW`: 1600 W.

Meaning:

- Signed range used by interface axes and schedule-editing controls.
- Negative values represent discharge.
- Positive values represent charge.
- These are not automatically the controller's physical command caps.

The current key names `minGridPower` and `maxGridPower` are misleading because the same values are also used for battery schedule controls. Phase 3 should introduce clearer names while retaining compatibility mappings during migration.

### Automation command caps

Intended migration values:

- `automation.maxDischargePowerW`: 1200 W magnitude.
- `automation.maxChargePowerW`: 1200 W magnitude.

Meaning:

- These are non-negative magnitudes used to clamp outgoing commands.
- Direction is determined by the command sign, not by storing a negative discharge cap.
- These remain automation-owned settings during the initial consolidation.

Phase 2 deliberately preserves the current 1200 W caps. Deciding whether an interface should offer values outside the controller caps is deferred as a separate behavioural and usability decision.

## Shared web and service policies

These values can be shared by both interfaces or related PHP services, but they are not physical battery facts.

### History service

Intended migration values:

- `history.defaultPreviousDays`: 3.
- `history.maximumPreviousDays`: 30.
- `history.timezone`: reference `installation.timezone` rather than maintaining another literal.

The existing interpretation remains three previous calendar days plus today.

### Schedule editor step

Intended migration value:

- `scheduleEditor.powerStepW`: 100 W.

Meaning:

- This is an interface input increment.
- It does not require automation to change power in 100 W steps.

### Price overview fallbacks

Intended old-interface values remain:

- `priceOverview.noDataPrice`: 0.24.
- `priceOverview.powerEfficiency`: 0.9.
- `priceOverview.netzeroReferenceW`: 200 W.
- `priceOverview.netzeroMinusReferenceW`: -180 W.
- `priceOverview.netzeroPlusReferenceW`: 300 W.
- `priceOverview.hourlyReferenceRanges`: empty object.

These remain old-interface calculation policies until a later comparison proves that another consumer needs identical behaviour.

## New-interface presentation values

These settings remain owned by the new interface and must not be loaded by automation.

### Status timing

- `status.refreshIntervalMs`: 20000.
- `status.requestTimeoutMs`: 8000.
- `status.staleAfterMs`: 90000.

### Nonlinear power scale

- 0% actual to 0% displayed.
- 1% actual to 10% displayed.
- 5% actual to 20% displayed.
- 10% actual to 25% displayed.
- 20% actual to 30% displayed.
- 30% actual to 40% displayed.
- 50% actual to 50% displayed.
- 75% actual to 75% displayed.
- 100% actual to 100% displayed.

### Battery colour anchors

- 0% and 20%: `#ff625f`.
- 30%: `#f2a84a`.
- 40%: `#c5ca62`.
- 50%: `#9ed17a`.
- 60% and 100%: `#79d484`.

### Grid presentation

The Phase 2 migration originally preserved different color and textual-state boundaries. A subsequent interface decision on 2026-08-03 aligned grid state and chevrons with the color configuration:

- Below -10 W: exporting, using the exporting color and at least one chevron.
- -10 W through +10 W: balanced, using a near-zero color and no chevrons.
- Above +10 W: importing, using the importing color and at least one chevron.

The state calculation reads these thresholds from the grid color-scale configuration, with matching defensive fallbacks, rather than maintaining a separate textual threshold.

### Timeline day parts

- Night: 00:00 through 06:00.
- Morning: 06:00 through 12:00.
- Afternoon: 12:00 through 18:00.
- Evening: 18:00 through 24:00.

## Automation-owned values

The following values remain in automation configuration during the initial consolidation:

- Test mode.
- Log level.
- Slow-charge start level and maximum power.
- Maximum charge and discharge command caps.
- Minimum power threshold.
- Minimum and maximum command delta.
- Reversal ramp settings.
- Automation loop interval.
- External API refresh interval.
- Net-zero target.
- Standby detection threshold.
- Status retention period.
- Device and meter selection.
- Schedule API URL used by the Raspberry Pi.

This ownership decision does not prevent selected parameters from becoming persistently editable through an interface later. That API and authorization design remains deferred.

## Configuration ownership decisions

### Common system ownership

The future common system configuration owns:

- Battery capacity.
- Minimum and maximum state of charge.
- Installation name, coordinates and timezone.
- Price-conversion values.

### Shared web ownership

Shared web configuration owns:

- Interface/schedule signed power range.
- Shared web endpoint selection.
- Shared history service policies.

### Interface ownership

Each interface owns its visual scales, colours, layout, refresh behaviour and interface-specific estimates unless explicitly promoted to shared web policy.

### Automation ownership

Automation owns controller-loop policies, command shaping, actuator caps, integration details and device credentials.

### Future write authority

- A future authorized configuration change from either interface will be persistent and authoritative for all consumers of that setting.
- No interface-to-configuration write API is designed or implemented in Phase 2.
- Phase 3 read-only common configuration work must not accidentally create a public write path.

## Flow and behaviour

The intended migration flow after Phase 2 is:

1. Phase 3 creates a read-only common configuration file and validation schema using the approved values.
2. Existing consumers continue using their current sources.
3. PHP and Python loaders are tested without switching runtime behaviour.
4. Later phases compare old and common values in shadow mode.
5. Consumers migrate one at a time with explicit rollback.
6. Persistent interface editing is designed only after read-only convergence is stable.

## Edge cases and failure modes

- When the common maximum becomes 91% but automation still reads 93%, then the migration status must show that automation has not switched yet.
- When a loader receives invalid minimum or maximum values, then it must reject the configuration rather than silently adjusting the pair.
- When an interface requests a signed power value above 1200 W, then current automation may clamp it even though the editor range extends to 1600 W.
- When code still uses the legacy shortwave coordinates, then it can calculate solar data for a different point than the canonical installation.
- When a timezone is absent, then consumers must not invent different local-time defaults.
- When a price precision is changed, then calculations and reports can differ even if displayed values appear similar.
- When presentation settings are placed in the system file, then automation becomes coupled to irrelevant browser behaviour.
- When automation-only safety settings are moved without equivalent validation, then consolidation can change real controller behaviour.
- When the synchronized copy is stale, then file presence alone does not prove that the Raspberry Pi uses the approved version.

## Phase 2 acceptance decisions

The following decisions are approved for Phase 3:

- Minimum state of charge: 15%.
- Maximum state of charge: 91%.
- Nominal battery capacity: 5760 Wh.
- Canonical location: Amsterdam, 52.3676/4.9041.
- Canonical timezone: `Europe/Amsterdam`.
- Price-conversion values remain as currently configured.
- Web/editor power range remains -1600 W through 1600 W during structural migration.
- Automation command caps remain 1200 W charge and 1200 W discharge during structural migration.
- Web power range and automation command caps are separate concepts.
- New-interface presentation values remain interface-specific.
- Automation loop and command-shaping settings remain automation-specific.
- Persistent interface editing remains deferred.

## Deferred behavioural decisions

- Whether the web/editor range should later be reduced to the automation command caps.
- Whether automation should later increase its command caps to match the editor range.
- Whether battery capacity should eventually influence automation decisions.
- Which automation-owned values will become editable from an interface.
- How synchronized persistent writes, versioning and acknowledgment will work.

These deferred questions do not block Phase 3 because Phase 3 can preserve the current distinct values and remain read-only.

## Related files

- [`configuration-baseline.md`](configuration-baseline.md): Phase 1 source inventory and discrepancies.
- [`main/includes/config_loader.php`](../main/includes/config_loader.php): current PHP configuration loader.
- [`automate/config_loader.py`](../automate/config_loader.py): current automation JSONC loader.
- [`main/includes/price_conversion.php`](../main/includes/price_conversion.php): current price-conversion implementation.
- [`main/data/resolve_schedule_conditions.php`](../main/data/resolve_schedule_conditions.php): current canonical-coordinate consumer.
- [`main/api/shortwave_radiation_api.php`](../main/api/shortwave_radiation_api.php): common-backed default shortwave location.
- [`pathlab/api/path_data.php`](../pathlab/api/path_data.php): legacy shortwave-coordinate and projection consumer.
- [`app/assets/js/power-bar-scale.js`](../app/assets/js/power-bar-scale.js): new-interface nonlinear scale.
- [`app/assets/js/battery-color-scale.js`](../app/assets/js/battery-color-scale.js): new-interface battery colours.
- [`app/assets/js/grid-exchange-color-scale.js`](../app/assets/js/grid-exchange-color-scale.js): new-interface grid colours.
