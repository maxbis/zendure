# Manual Override Save Flow

## Purpose

This document describes how the old GUI saves a manual schedule override, how the automatic N+1-hour boundary limits an hourly override, and how the running automation process is told to use the new schedule immediately.

The behavior applies to saves from the schedule edit modal in `main/charge_schedule_mobile.php`.

## Location

The flow is implemented across:

- `main/assets/js/edit_modal.js` — validates the form, sends the save request, requests a backend schedule reload, and refreshes the displayed data.
- `main/api/charge_schedule_api.php` — validates and stores overrides when the direct schedule API is configured.
- `main/data/api/data_api.php` — provides the equivalent save behavior when the generic data API is configured.
- `main/api/charge_schedule_functions.php` — normalizes entries, adds the N+1 boundary, removes outdated concrete entries, and writes the schedule atomically.
- `main/assets/js/automation_status.js` — exposes the backend schedule-reload request.
- `main/api/refresh_schedule_proxy.php` — forwards the reload command to the running automation backend.

## Inputs and Outputs

The edit modal sends a `PUT` request with this shape:

```json
{
  "key": "202608021400",
  "entry": {
    "value": 800
  },
  "originalKey": "202608021300"
}
```

- `key` is a 12-character `YYYYMMDDHHmm` key and may contain `*` wildcards.
- `entry.value` is a numeric watt value or a supported mode such as `auto`, `netzero`, `netzero-`, or `netzero+`.
- `entry.min_power` and `entry.max_power` are optional for dynamic modes.
- `originalKey` is included when an existing entry is edited. When it differs from `key`, the old key is removed.

A successful save response includes:

```json
{
  "success": true,
  "auto_boundary_added": "202608021500"
}
```

`auto_boundary_added` is `null` when no N+1 boundary was needed or allowed.

## Flow and Behavior

### 1. Validate and normalize the override

1. The modal builds the schedule key from the entered date and time.
2. Empty date or time fields become wildcards.
3. The API validates the 12-character key and normalizes the entry value and optional bounds.
4. When an edited entry changes key, the API removes `originalKey`.
5. The new entry overwrites any existing entry with the same key.

If validation fails, nothing is written and the backend refresh is not requested.

### 2. Add the N+1-hour boundary

A non-`auto` manual override is a schedule change point. Without a later boundary, its value could carry forward through later slots on the same date. For a concrete override on a whole hour, the save logic therefore tries to insert an `auto` entry at the next whole hour.

Example:

```json
{
  "202608021400": { "value": 800 },
  "202608021500": { "value": "auto" }
}
```

The result is:

- At 14:00, the exact manual override supplies `800 W`.
- At 15:00, `auto` stops the earlier concrete manual value from carrying forward.
- From 15:00, normal wildcard and condition-rule resolution can take over again.
- `auto` does not mean `0 W`; it is a resolution boundary.

The N+1 entry is added only when all of the following are true:

- The override key contains 12 concrete digits with no wildcards.
- The time is exactly on a whole hour (`mm` is `00`).
- The hour is between `00:00` and `22:00`.
- The saved value is not already `auto`.
- No existing entry at N+1 already applies to that date.

When an applicable N+1 entry already exists, it is preserved. This includes a recurring wildcard entry such as `********1500`.

No boundary is inserted after a 23:00 override because the concrete dated entry stops matching when the next day begins.

### 3. Remove outdated concrete entries

Before writing, the API silently runs `cleanOutdatedScheduleEntries()`:

- When a key has a fully concrete `YYYYMMDD` date older than yesterday, then it is removed.
- When a key is dated yesterday, then it is retained for error tracking.
- When a key is dated today or in the future, then it is retained.
- When the date contains any `*` wildcard, then it is retained.
- When a key is malformed or does not have a fully concrete date, then this cleanup helper leaves it untouched.

The cleanup has no confirmation dialog or success notification. The override, its optional N+1 boundary, and the retained schedule are written together through the atomic schedule writer.

### 4. Force the automation backend to reread the schedule

After the API confirms the write, the browser calls `requestScheduleReloadBeforePageReload()`. That request goes through `main/api/refresh_schedule_proxy.php` and sends the `refresh_schedule` command to the running automation backend.

This avoids waiting for the backend's normal schedule-fetch interval before the new override takes effect.

### 5. Refresh the old-GUI display

Finally, the browser calls `window.refreshScheduleAndPricesImmediate()` so the entries list, resolved schedule, graphs, and other schedule-dependent UI use the newly stored data.

The backend reread and browser refresh are separate operations:

- The backend reread updates the running control process.
- The browser refresh updates what the operator sees.

## Edge Cases and Failure Modes

- When the schedule write fails, then the modal remains in the failure path and neither the backend reread nor the normal post-save UI refresh is started.
- When the schedule write succeeds but the backend reread fails, then the saved override remains stored. The UI reports that the override was saved but the backend refresh failed, and it still refreshes the displayed schedule.
- When an override is saved for a concrete date older than yesterday, then retention cleanup removes it during the same save. Yesterday is intentionally retained for troubleshooting.
- When a manual entry is saved at a non-whole-hour time such as `14:30`, then no automatic N+1 boundary is inserted; the normal change-point resolution rules apply.
- When a wildcard or partially wildcarded key is saved, then no automatic N+1 boundary is inserted.
- When an applicable entry already exists at N+1, then it is not overwritten by an automatic `auto` boundary.
- When the saved value is `auto`, then another `auto` boundary is not created.

## Related Files

- [Schedule overview](schedule-overview.md)
- [Refresh functions](refresh-functions.md)
- [Old GUI user manual](../user_manuals/charge_schedule_mobile_user_manual.md)
- [Schedule resolution technical reference](../data/schedule-resolution-technical.md)
