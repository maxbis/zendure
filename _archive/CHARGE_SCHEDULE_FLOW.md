# Charge Schedule Entry Flow

When adding/editing an entry in the Charge Schedule Manager, here's the complete flow:

## Flow Overview

1. **User clicks "Save" in the modal** → `edit_modal.js` `handleSave()`
   - Sends **POST** (new entry) or **PUT** (edit entry) to the API

2. **API endpoint: `schedule/api/charge_schedule_api.php`**
   - **POST** (lines 46-66): Adds entry to schedule array, writes to JSON via `writeScheduleAtomic()`
   - **PUT** (lines 67-92): Updates entry (removes old key if changed), writes to JSON via `writeScheduleAtomic()`

3. **After successful save** → `onSaveCallback()` is triggered (which is `refreshData`)

4. **`refreshData()`** (in `charge_schedule.js` line 129) makes a **GET** request to the API

5. **API GET endpoint** (lines 21-45): 
   - Loads the schedule from JSON
   - Calls `resolveScheduleForDate($schedule, $date)` (line 36) — **this is where recalculation happens**
   - Returns entries, resolved schedule, current hour/time

6. **UI updates** with the recalculated data

## Key Points

- **The JSON is written** in `charge_schedule_api.php` via `writeScheduleAtomic()` (defined in `charge_schedule_functions.php` line 24)
- **Recalculation happens** in `resolveScheduleForDate()` (defined in `charge_schedule_functions.php` line 75), which is called during the GET request after save
- The recalculation resolves all schedule entries for the date, applying wildcards and specificity rules

## Summary

**POST/PUT** writes to JSON → `refreshData()` calls GET → GET calls `resolveScheduleForDate()` to recalculate → UI updates

