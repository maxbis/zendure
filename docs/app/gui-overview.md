# Old and new GUI overview

## Purpose

This document defines the names, locations, and current responsibilities of the two Zendure user interfaces. Project documentation and discussions should refer to them as the **old GUI** and the **new GUI**.

The word "old" identifies the established interface during the migration. It does not mean that the interface is unsupported or can be removed: several operational features are still available only in the old GUI.

## Terminology

- **New GUI**: the monitoring-focused interface served from `/app/`.
- **Old GUI**: the established operational interface served from `/main/charge_schedule_mobile.php`.
- `/main/` redirects to the old GUI.
- Use the qualified names **old GUI** and **new GUI** in documentation, issues, and change descriptions. Avoid ambiguous terms such as "the app", "mobile app", or "legacy GUI" when either interface could be meant.
- Existing source comments or visible labels can still contain "legacy" during the migration. This documentation uses **old GUI** consistently.

## Location

### New GUI

- Entry point: [`app/index.php`](../../app/index.php)
- Styles: [`app/assets/css/app.css`](../../app/assets/css/app.css)
- Client-side behavior: [`app/assets/js/`](../../app/assets/js/)
- Browser route: `/app/`

### Old GUI

- Entry point: [`main/charge_schedule_mobile.php`](../../main/charge_schedule_mobile.php)
- Redirecting directory entry point: [`main/index.php`](../../main/index.php)
- Styles and client-side behavior: [`main/assets/`](../../main/assets/)
- Browser route: `/main/charge_schedule_mobile.php`

## Inputs and outputs

### New GUI

The new GUI reads configuration during the PHP request and retrieves live data through existing endpoints under `main/`.

Important inputs include:

- current battery and grid status from `main/api/charge_status_all_proxy.php`
- electricity-price data from the configured price endpoints
- battery history from `main/api/app_energy_history.php`
- authentication from the shared login validation

It presents:

- current charge or discharge status, battery level, and grid exchange
- battery Energy and Health details, including stored and usable energy, projected energy, controller and pack temperatures, pack charge levels, and Wi-Fi signal
- prices and the current energy plan for today or tomorrow
- four days of hourly battery energy history
- charged, discharged, and net totals for the selected day
- a link to the old GUI for functions that have not moved yet

### Old GUI

The old GUI reads the shared configuration, schedule files, price data, current status, and automation state.

It presents and modifies:

- detailed battery and grid status
- electricity prices and energy history
- charge and discharge schedules
- schedule rules and conditions
- automation status and related operational controls

## Flow and behavior

1. Authentication is checked before either GUI exposes protected information.
2. The new GUI loads its presentation from `app/` but continues to use shared configuration and backend endpoints in `main/`.
3. The new GUI is the preferred monitoring interface.
4. When a user activates a battery detail trigger, the non-modal dialog opens on its Energy view and can switch to live battery Health details.
5. When a user selects a day in the new GUI's four-day history, the summary cards show totals for that selected calendar day. For today, totals cover the available readings up to the current time.
6. When schedule editing, rule editing, or an automation function is needed, the user continues in the old GUI.
7. Backend changes to shared APIs must be tested against both GUIs.

## Edge cases and failure modes

- When a shared status, price, or history endpoint fails, then one or both GUIs can show stale, incomplete, or unavailable data even if their page assets load correctly.
- When a deployment updates HTML and JavaScript at different times, then a browser can temporarily run mismatched new-GUI assets. A forced refresh clears the stale asset combination.
- When documentation mentions only `/main/`, then readers should treat it as the old GUI because `main/index.php` redirects there.
- When a feature exists only in the old GUI, then calling the new GUI a complete replacement is incorrect.
- When the old GUI is eventually retired, then its routes, feature ownership, migration status, and all references in this document must be updated together.

## Related files

- [New GUI current energy status and battery details](assets/js/current-energy-status.md)
- [New GUI prices and energy plan](assets/js/price-plan.md)
- [New GUI shared system configuration](shared-system-configuration.md)
- [Old GUI page description](../main/page-description.md)
- [Old GUI page layout](../main/page-layout.md)
- [Old GUI schedule architecture](../main/schedule-overview.md)
- [Old GUI user manual](../user_manuals/charge_schedule_mobile_user_manual.md)
- [Old GUI energy graph](../main/energy-graph-mobile.md)
