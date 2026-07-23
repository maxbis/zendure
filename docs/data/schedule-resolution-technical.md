# Schedule Resolution — Technical Reference

> **Scope:** End-to-end pipeline from raw schedule storage to the final resolved API output at  
> `GET /main/data/api/data_api.php?type=schedule&resolved=1`

---

## 1. Overview

The Zendure charge schedule system has two independent layers of rules that are resolved and merged on every API request:

| Layer | Source file | Description |
|-------|------------|-------------|
| **Base schedule** | `main/data/charge_schedule.json` | Manually set rules with wildcard key patterns |
| **Conditional rules** | `main/data/charge_schedule_conditions.json` | Price/time/sun-based rules evaluated against today's prices |

These are merged with a clear **priority model**: manual exact-date entries always win; conditional rules may fill/override wildcard or empty slots.

```
charge_schedule.json          charge_schedule_conditions.json
        │                                    │
        │  resolveScheduleForDate()           │  resolve_schedule_conditions.php
        ▼                                    ▼
  Base resolved slots              Conditional resolved items
        │                                    │
        └─────────── mergeResolvedWithConditional() ──────────▶ Final resolved array
                                                                        │
                                              data_api.php?type=schedule&resolved=1
```

---

## 2. Key Format

All schedule entries use a **12-character key** with the pattern `YYYYMMDDHHmm`:

| Characters | Meaning | Wildcard? |
|-----------|---------|-----------|
| 0–3 | Year (`YYYY`) | `****` |
| 4–5 | Month (`MM`) | `**` |
| 6–7 | Day (`DD`) | `**` |
| 8–9 | Hour (`HH`) | `**` |
| 10–11 | Minute (`mm`) | `**` |

`*` in any position is a wildcard matching any digit.

### Examples

| Key | Meaning |
|-----|---------|
| `202603021800` | Exactly 2026-03-02 at 18:00 |
| `********1000` | Every day at 10:00 |
| `20260316****` | All slots on 2026-03-16 |
| `************` | Every slot always (full wildcard) |

---

## 3. Value Types

| Value | Type | Meaning |
|-------|------|---------|
| `300` | integer (watts) | Charge at 300 W from grid |
| `-800` | integer (watts) | Discharge at 800 W to grid |
| `0` | integer | Zero output (idle) |
| `"netzero"` | string | Dynamic mode that reduces grid import/export toward zero; charging depends on `NETZERO_BI_DIRECTIONAL` |
| `"netzero+"` | string | Charge-only dynamic mode that reduces grid export toward zero |

---

## 4. Base Schedule Storage — `charge_schedule.json`

**Location:** `main/data/charge_schedule.json`  
**Format:** JSON object, keys are 12-char patterns, values are objects with a required `value` field.

```json
{
  "********0000": { "value": 0 },
  "********1030": { "value": "netzero+" },
  "********1530": { "value": 0 },
  "********1800": { "value": "netzero", "min_power": -700, "max_power": -100 },
  "********2000": { "value": 0 }
}
```

Optional `min_power` and `max_power` are allowed only for `"netzero"` and `"netzero+"` entries. They are stored in `charge_schedule.json`, propagated into resolved slots, and used by automation to clamp the dynamic watt result.

This file is read and written atomically by `writeScheduleAtomic()` / `writeDataFileAtomic()` (write to `.tmp` then rename) to avoid partial reads.

---

## 5. Base Schedule Resolution — `resolveScheduleForDate()`

**File:** `main/api/charge_schedule_functions.php`

### Algorithm

1. **Build time-slot list:** All whole-hour slots `0000–2300`, plus any non-wildcard time parts from keys in the schedule.
2. **For each slot** (chronologically):
   - Collect all schedule entries that **match** the slot:
     - Date part of key matches the target date (wildcard or exact)
     - Time part of key is `<=` the slot time **or** is a wildcard
   - **Sort candidates** by priority:
     1. Entries with a specific time beat entries with a wildcard time
     2. More recent time wins (latest-setting-before-slot wins)
     3. Higher specificity (fewer wildcards) wins
     4. Lexicographically descending key as a tie-breaker
   - First result is the **selected value**; `null` if no match.
3. **Output:** Array of `{ time, value, key }` objects for every slot, with optional `min_power` / `max_power` when the selected raw entry defines them.

```json
[
  { "time": "0000", "value": 0,         "key": "********0000" },
  { "time": "0100", "value": 0,         "key": "********0000" },
  { "time": "1030", "value": "netzero+","key": "********1030" },
  { "time": "1530", "value": 0,         "key": "********1530" },
  { "time": "1800", "value": "netzero", "key": "********1800", "min_power": -700, "max_power": -100 },
  { "time": "2000", "value": 0,         "key": "********2000" }
]
```

> **Important:** The resolver is a "last-write-before-slot wins" model. Entry `********1030` controls all slots starting at 10:30 until the next entry (`********1530`) takes over.

---

## 6. Conditional Rules Storage — `charge_schedule_conditions.json`

**Location:** `main/data/charge_schedule_conditions.json`  
**Format:** JSON array of rule objects; order matters (first match wins per hour).

### Rule Structure

```json
{
  "name": "Discharge on spread",
  "value": -800,
  "enabled": true,
  "month": "2,3",
  "min_time": 16,
  "max_time": 22,
  "conditions": [
    { "field": "spread_price", "op": ">=", "value": 12 },
    { "field": "ranking",      "op": ">=", "value": 23 },
    { "field": "min_price_hour", "op": "<", "value_ref": "max_price_hour" }
  ]
}
```

### Top-level Rule Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Human-readable label |
| `value` | int / `"netzero"` / `"netzero+"` / `"netzero-"` | Output action when rule fires |
| `enabled` | bool | `false` disables the rule (default: `true`) |
| `key` | string | Optional 12-char key pattern (default: `************`) |
| `month` | string | Comma-separated months, e.g. `"10,11,12,1,2,3"` |
| `hour` | string | Comma-separated hours (0–23), e.g. `"17,18,19"` |
| `min_time` | int/string | Inclusive lower hour bound |
| `max_time` | int/string | Inclusive upper hour bound |
| `min_power` | int/null | Optional lower signed watt bound for primary `netzero` / `netzero+` / `netzero-` values |
| `max_power` | int/null | Optional upper signed watt bound for primary `netzero` / `netzero+` / `netzero-` values |
| `fallback_value` | int/string | Used at runtime if `electricity_level` condition is false or cannot be evaluated |
| `conditions` | array | Static condition rows are combined by `condition_relation` |
| `condition_relation` | string | Optional relation for static `conditions[]` rows: `and` or `or` (default: `and`) |

### Condition Fields

| `field` | Description | Unit |
|---------|-------------|------|
| `price` | Current hour price | cents/kWh |
| `ranking` | Hour rank by ascending price (1 = cheapest, 24 = most expensive) | integer |
| `min_price` | Day minimum price | cents/kWh |
| `max_price` | Day maximum price | cents/kWh |
| `spread_price` | `max_price - min_price` | cents/kWh |
| `min_price_hour` | Hour of day minimum price | 0–23 |
| `max_price_hour` | Hour of day maximum price | 0–23 |
| `max_price_hour_am` | Hour of AM half-day maximum price | 0–11 |
| `max_price_hour_pm` | Hour of PM half-day maximum price | 12–23 |
| `sunrise_hour` | Sunrise hour (floored) from lat/lon | 0–23 |
| `sunset_hour` | Sunset hour (ceil) from lat/lon | 0–23 |
| `sunrise_offset_hour` | Hour relative to sunrise (`hour >= sunrise + N`) | integer |
| `sunset_offset_hour` | Hour relative to sunset (`hour <= sunset + N`) | integer |
| `month` | Calendar month | 1–12 |
| `hour` | Hour of day | 0–23 |
| `min_time` | Lower hour bound (inclusive) | 0–23 |
| `max_time` | Upper hour bound (inclusive) | 0–23 |
| `electricity_level` | Battery SOC (**runtime-only**, not pre-evaluated) | % |

### Operators

`>` `>=` `<` `<=` `==` `!=` `in`  
For list fields (`month`, `hour`), use `in` (the default).

### Condition Relation

- `condition_relation = and` means all static `conditions[]` rows must match
- `condition_relation = or` means any static `conditions[]` row may match
- top-level filters `month`, `hour`, `min_time`, `max_time` remain separate AND prefilters
- runtime-only rows such as `electricity_level` force the effective relation back to `and`

### `value` vs `value_ref`

A condition operand can be:
- `"value": 12` — static literal
- `"value_ref": "max_price_hour"` — resolved dynamically from price context

- `"value_ref": "max_price", "value": -1` - resolved dynamically, then offset by `-1`

When both `value_ref` and numeric `value` are present, `value` is used as an additive offset. For example, `price > max_price - 1` is stored as `"field": "price", "op": ">", "value_ref": "max_price", "value": -1`.

Supported `value_ref` targets: `min_price`, `max_price`, `spread_price`, `min_price_hour`, `max_price_hour`, `max_price_hour_am`, `max_price_hour_pm`, `sunrise_hour`, `sunset_hour`.

---

## 7. Conditions Resolution — `resolve_schedule_conditions.php`

**File:** `main/data/resolve_schedule_conditions.php`  
**URL:** `https://zendure.qool.ovh/main/data/resolve_schedule_conditions.php`  
**Method:** GET only; returns JSON.

### Process

1. Load `charge_schedule_conditions.json`
2. Normalize rules:
   - Both list format (`[{…}]`) and object format (`{"key": {…}}`) are supported
   - Rules are sorted by **key specificity** (fewer wildcards = evaluated first) then by their original list order
3. For **today** and **tomorrow** (Europe/Amsterdam timezone):
   - Find the price file at `main/data/price/YYYYMM/priceYYYYMMDD.json`
   - Skip dates without a price file
   - Build **price context** from price file:
     - `min_price`, `max_price`, `spread_price`, `min_price_hour`, `max_price_hour`, `max_price_hour_am`, `max_price_hour_pm`
     - `ranking_by_hour` — map `hour → rank`
   - Note: this resolver still uses JSON price files. The daily report price path is separate and uses MariaDB `price_ticks`.
   - Build **sun context** (from `main/config/config.json` lat/lon, defaulting to Amsterdam):
     - `sunrise_hour`, `sunset_hour`, `sunrise_time`, `sunset_time`
4. For each hour 0–23:
   - Walk rules in order
   - Filter by `enabled`, key pattern match
   - Evaluate top-level filters (`month`, `hour`, `min_time`, `max_time`) as AND filters
   - Evaluate static `conditions[]` rows using `condition_relation`
   - If `condition_relation` is `and`, all static rows must pass; if `or`, any static row may pass
   - **Runtime-only** conditions (`electricity_level`) are skipped here; they are passed through as metadata
   - **First matching rule fires** — break
5. Emit `{ time, value, ranking, rule_name?, rule_index?, runtime_conditions?, fallback_value?, min_power?, max_power? }` per matched hour

### Output Format

```json
{
  "success": true,
  "resolved": [
    {
      "date": "20260302",
      "min_price": 4.5,
      "max_price": 30.2,
      "spread_price": 25.7,
      "min_price_hour": 3,
      "max_price_hour": 18,
      "max_price_hour_am": 11,
      "max_price_hour_pm": 18,
      "sunrise_hour": 7,
      "sunset_hour": 18,
      "sunrise_time": "07:12",
      "sunset_time": "18:03",
      "ranking": { "1": 3, "2": 4, "24": 18 },
      "items": [
        {
          "time": "0300",
          "value": 800,
          "ranking": 1,
          "rule_name": "Charge on Spread",
          "rule_index": 3
        },
        {
          "time": "1400",
          "value": "netzero",
          "ranking": 20,
          "rule_name": "Top 10 50% PM",
          "rule_index": 6,
          "min_power": -700,
          "max_power": -100,
          "runtime_conditions": [
            { "field": "electricity_level", "op": ">=", "value": 50 }
          ],
          "fallback_value": null
        }
      ]
    }
  ]
}
```

> **Note:** `runtime_conditions` are present when a rule has conditions (like `electricity_level`) that cannot be evaluated statically. The active automation layer (`automate_www.py`) is responsible for handling these at runtime and deciding to apply `value` or `fallback_value`.

---

## 8. Final Merge — `data_api.php?type=schedule&resolved=1`

**File:** `main/data/api/data_api.php`  
**Full URL example:** `http://localhost/zendure/main/data/api/data_api.php?type=schedule&resolved=1`  
**Method:** GET

### What it does

1. Load `charge_schedule.json`
2. Call `resolveScheduleForDate($schedule, $date)` → base resolved slots
3. If `include_conditions: true` in `main/config/config.json`:
   - Call `mergeResolvedWithConditional($resolved, $date)`:
     - Invokes `resolve_schedule_conditions.php` via `shell_exec`
     - Matches returned items by `time` to base-resolved slots
     - **Priority model during merge:**
       - Slot has exact-date key **and value ≠ 0** → **keep base value, ignore condition**
       - Slot is empty, has a wildcard key, **or has value = 0** → **replace with condition value**
      - Adds metadata: `source: "condition"`, `rule_name`, `rule_index`, `runtime_conditions`, `fallback_value`, `min_power`, `max_power`
4. Build UI entries (all raw schedule entries, sorted by key)
5. Return full response

### `include_conditions` Config Flag

In `main/config/config.json`:
```json
{
  "include_conditions": true
}
```
When `false` (or absent), the conditions system is entirely bypassed and only the base schedule is returned.

### Response Format

```json
{
  "success": true,
  "date": "20260302",
  "currentHour": "1400",
  "currentTime": "1423",
  "resolved": [
    { "time": "0000", "value": 0,         "key": "********0000" },
    { "time": "0300", "value": 800,       "key": null,            "source": "condition", "rule_name": "Charge on Spread", "rule_index": 3 },
    { "time": "1030", "value": "netzero+","key": "********1030" },
    { "time": "1400", "value": "netzero", "key": null,            "source": "condition", "rule_name": "Top 10 50% PM",    "rule_index": 6, "min_power": -700, "max_power": -100,
      "runtime_conditions": [{ "field": "electricity_level", "op": ">=", "value": 50 }] },
    { "time": "1800", "value": "netzero", "key": "********1800" }
  ],
  "entries": [
    { "key": "********0000", "entry": { "value": 0 } },
    { "key": "********1030", "entry": { "value": "netzero+" } },
    { "key": "********1530", "entry": { "value": 0 } },
    { "key": "********1800", "entry": { "value": "netzero" } },
    { "key": "********2000", "entry": { "value": 0 } }
  ]
}
```

`resolved` = every active slot for the day (merged).  
`entries` = raw contents of `charge_schedule.json` for display in the UI table.

---

## 9. Runtime Bound Enforcement — `device_controller.py`

Resolved `min_power` and `max_power` metadata is consumed at runtime by `automate/device_controller.py` when the active primary slot value is `netzero`, `netzero+`, or `netzero-`.

### Runtime Order

1. Calculate raw dynamic result
2. If runtime conditions failed and `fallback_value` is active, skip primary rule bounds
3. Apply `min_power` / `max_power` as a signed clamp for primary rule values
4. Apply `ReversalRampGuard` to the bounded target
5. Apply max-delta limiting
6. Apply battery SoC and hardware/device caps before writing to the device

### Bound Semantics

- Bounds are applied directly as signed power limits
- Bounds apply only to the primary slot value, not to `fallback_value`
- Fallback values do not inherit primary rule bounds
- Missing or `null` bounds mean "leave the old behavior unchanged"
- Invalid bounds are ignored for that cycle
- If both are present and `min_power > max_power`, both are ignored for that cycle

Examples:

- raw `-50`, `min_power=-700`, `max_power=-100` -> `-100`
- raw `-300`, `min_power=-200`, `max_power=-100` -> `-200`
- raw `+300`, `min_power=100`, `max_power=200` -> `+200`
- raw `-50`, `min_power=-300`, `max_power=300` -> `-50`

### Reversal Guard Interaction

`ReversalRampGuard` has priority during true charge/discharge reversals.

- When the guard changes the raw dynamic result, `min_power` / `max_power` is deferred for that cycle
- Once the reversal settles and the guard is no longer active, signed-range enforcement resumes normally

This keeps reversal protection intact while still allowing bounded dynamic behavior in stable conditions.

---

## 10. Priority Summary

| Priority | Source | Condition |
|----------|--------|-----------|
| **1 – Highest** | Base schedule | Entry has **exact date+time** (no wildcards) **and value ≠ 0** |
| **2** | Conditional rules | Condition fires on slot that is empty, wildcard, **or value = 0** |
| **3 – Lowest** | Base schedule wildcard | Entry has wildcard pattern |

This means:
- A rule `202603021800 → -800` (exact, non-zero) **cannot** be overridden by any condition.
- An entry `202603021800 → 0` (exact, zero) **can** be overridden by a condition — `0` is transparent.
- An entry `********1800 → "netzero"` **can** be overridden by a condition for that hour.
- An empty slot (no schedule entry) **can** be filled by a condition.

### Zero as "auto" / bookend

Because `0` is transparent to conditions, it serves as a natural **"reset to auto"** marker. When you want to override just one hour inside a condition-controlled range, add two entries:

```
202603021200 → "netzero"   ← your one-hour override (blocks conditions at 12:00)
202603021300 → 0           ← bookend: conditions take back control from 13:00
```

The `0` at 13:00 does **not** block the rules — conditions fill that slot and all subsequent ones as normal.

> **Note:** If you genuinely need to force idle at a specific hour against all conditions, use a small non-zero value like `1` (1 watt) instead of `0`.

---

## 11. Price Files

**Location:** `main/data/price/YYYYMM/priceYYYYMMDD.json`  
**Example:** `main/data/price/202603/price20260302.json`

```json
{
  "00": 0.085,
  "01": 0.072,
  "02": 0.061,
  ...
  "23": 0.182
}
```

Keys are 2-digit hour strings (`"00"` – `"23"`), values are prices in **EUR/kWh**.  
The conditions resolver multiplies by 100 internally to work in **cents/kWh**.

---

## 12. Sun Context

Latitude and longitude are read from `main/config/config.json`:
```json
{ "latitude": 52.3676, "longitude": 4.9041 }
```
Defaults to Amsterdam (52.3676°N, 4.9041°E) if not set.

PHP's `date_sun_info()` is used to compute sunrise/sunset timestamps in Europe/Amsterdam timezone:
- `sunrise_hour` = `floor(sunrise_localtime_hours)`
- `sunset_hour`  = `ceil(sunset_localtime_hours)`

`sunrise_offset_hour` and `sunset_offset_hour` conditions use these anchors with a numeric offset:
```
# Example: fire during hours 3h after sunrise up to 3h before sunset
{ "field": "sunrise_offset_hour", "op": ">=", "value": 3 }
{ "field": "sunset_offset_hour",  "op": "<=", "value": -3 }
```

---

## 13. File Locations Summary

| File | Path |
|------|------|
| Base schedule | `main/data/charge_schedule.json` |
| Conditions rules | `main/data/charge_schedule_conditions.json` |
| Schedule functions | `main/api/charge_schedule_functions.php` |
| Conditions resolver | `main/data/resolve_schedule_conditions.php` |
| Data API entry point | `main/data/api/data_api.php` |
| Price files | `main/data/price/YYYYMM/priceYYYYMMDD.json` |
| Main config | `main/config/config.json` |

---

## 14. Related Documentation

- [schedule-overview.md](../main/schedule-overview.md) — UI/JS module architecture, data flow from the frontend perspective
- [edit_rules_user_manual.md](../user_manuals/edit_rules_user_manual.md) — How to use the rule editor UI
- [charge_schedule_mobile_user_manual.md](../user_manuals/charge_schedule_mobile_user_manual.md) — Mobile UI user manual
- [min-max-values_user_manual.md](../user_manuals/min-max-values_user_manual.md) — User guide for `min_power` / `max_power`
- [data-api.md](api/data-api.md) — Full data API reference
