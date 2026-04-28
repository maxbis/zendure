# Schedule Agent API Contract

## Purpose and Scope

This document defines the API contract for a schedule agent that creates charge and discharge plans for the Zendure automation runtime.

The primary objective of the agent is to produce a **resolved schedule** that the current Python automation loop can consume directly without code changes. The current consumer expects:

- a JSON response
- a top-level `success` field
- a flat `resolved` array for the **current local date**

All time semantics in this contract use the `Europe/Amsterdam` timezone.

This document defines two related interfaces:

1. A **compatibility API** that is a drop-in replacement for the current schedule endpoint configured via `apiUrl`
2. A **future planner API** that exposes a richer planning surface for optimization, reasoning, and adapters

The compatibility API is the stable runtime contract. The planner API is a richer authoring and orchestration contract that may be used to generate the compatibility payload.

---

## Compatibility API

### Purpose

The compatibility API is the exact contract the current automation runtime can call directly through `apiUrl`.

The current Python consumer:

- fetches the endpoint periodically
- expects `success: true` and `resolved: [...]`
- stores the `resolved` list as the active schedule for the current day
- chooses the entry with the largest `time <= current HHMM`

### Endpoint

- `GET /schedule/resolved`

### Media Type

- `application/json`

### Request

- Method: `GET`
- Request body: none
- Query parameters: none required for the current automation runtime

Optional future query parameters may be added if they are backward compatible, but the current runtime must not depend on them.

### Response Shape

#### Required Top-Level Fields

- `success`: boolean
- `resolved`: array

#### Optional Top-Level Fields

- `date`: local schedule date in `YYYY-MM-DD`
- `timezone`: should be `Europe/Amsterdam`
- `generated_at`: ISO-8601 timestamp
- `agent_version`: string version identifier
- `meta`: object for producer-specific metadata

Unknown top-level fields must be safe for existing consumers to ignore.

### `resolved[]` Entry Schema

Each item in `resolved` must be an object.

#### Required Fields

- `time`
  - Type: string preferred, integer tolerated
  - Format: `HHMM`
  - Meaning: local Amsterdam time at which the slot becomes effective
- `value`
  - Type: integer or string
  - Allowed values:
    - integer watt value, for example `0`, `300`, `-800`
    - `"netzero"`
    - `"netzero+"`
    - `"netzero-"`

#### Recommended Field

- `key`
  - Type: string
  - Meaning: stable identifier for the resolved source slot or rule

#### Optional Fields Already Supported by the Runtime

- `min_power`
  - Type: integer
  - Meaning: lower signed watt clamp for dynamic modes
- `max_power`
  - Type: integer
  - Meaning: upper signed watt clamp for dynamic modes
- `runtime_conditions`
  - Type: array
  - Meaning: runtime-only conditions evaluated by Python
- `fallback_value`
  - Type: integer or string
  - Allowed string values:
    - `"netzero"`
    - `"netzero+"`
    - `"netzero-"`
- `rule_name`
  - Type: string
  - Meaning: human-readable rule label
- `rule_index`
  - Type: integer
  - Meaning: stable rule order or rule identifier in the producing system

### Runtime Condition Schema

Each `runtime_conditions[]` item must be an object with:

- `field`
  - Supported value: `"electricity_level"`
- `op`
  - Supported values:
    - `>`
    - `>=`
    - `<`
    - `<=`
    - `==`
    - `!=`
- `value`
  - Type: number
  - Meaning: threshold to compare against the battery SoC percentage at runtime

Example:

```json
{
  "field": "electricity_level",
  "op": ">=",
  "value": 60
}
```

### Compatibility API Behavior

The compatibility payload must obey all of the following rules:

- `resolved` must be sorted in ascending order by `time`
- `resolved` must represent the **current local day**
- the response must include a slot effective from `0000` or earlier for that day
- the producer must return a **resolved schedule**, not raw wildcard rules
- the runtime will select the slot with the largest `time <= current HHMM`
- `min_power` and `max_power` are only valid for:
  - `"netzero"`
  - `"netzero+"`
  - `"netzero-"`
- when both `min_power` and `max_power` are present, `min_power <= max_power` must hold
- `runtime_conditions` are passed through and evaluated by Python, not by the compatibility resolver itself

The compatibility endpoint should avoid emitting `null` values for active scheduling decisions. The current runtime can degrade `None` or a missing active slot effectively to `0`, but producers should not depend on that fallback.

### Valid Response Example

```json
{
  "success": true,
  "resolved": [
    {
      "time": "0000",
      "value": 0,
      "key": "********0000"
    },
    {
      "time": "0900",
      "value": 400,
      "key": "202604280900"
    },
    {
      "time": "1500",
      "value": "netzero",
      "key": "202604281500",
      "min_power": -700,
      "max_power": -100,
      "runtime_conditions": [
        {
          "field": "electricity_level",
          "op": ">=",
          "value": 60
        }
      ],
      "fallback_value": 0,
      "rule_name": "battery_export_window",
      "rule_index": 4
    },
    {
      "time": "2000",
      "value": 0,
      "key": "202604282000"
    }
  ],
  "date": "2026-04-28",
  "timezone": "Europe/Amsterdam",
  "generated_at": "2026-04-28T14:58:00+02:00",
  "agent_version": "v1",
  "meta": {
    "source": "schedule-agent",
    "planner_mode": "compatibility"
  }
}
```

### Error Contract

Transport and server failures should use appropriate non-2xx HTTP responses.

Application-level JSON failures should use this shape:

```json
{
  "success": false,
  "error": "human-readable explanation"
}
```

Rules:

- never return `success: true` without `resolved`
- do not return malformed JSON
- keep `error` human-readable and suitable for logs

---

## Future Planner API

### Purpose

The planner API is a richer planning surface for schedule generation. It is intended for future agent implementations that optimize against prices, battery state, forecasts, and constraints.

This API is not the primary runtime contract. Instead, it should produce:

- a planner-native `plan`
- a derived `resolved` array compatible with the current Python automation runtime

### Endpoint

- `POST /agent/schedule/plan`

### Media Type

- `application/json`

### Request Shape

The request body should contain these top-level sections:

- `context`
- `constraints`
- `prices`
- `battery`
- `site`
- `strategy`

#### `context`

Required fields:

- `date`
  - Type: string
  - Format: `YYYY-MM-DD`
- `timezone`
  - Type: string
  - Expected value: `Europe/Amsterdam`

Optional fields:

- `generated_at`
- `request_id`
- `horizon_days`

#### `constraints`

Suggested fields:

- allowed charge windows
- allowed discharge windows
- reserve SoC target
- minimum reserve floor
- hard no-charge windows
- hard no-discharge windows
- import/export caps
- manual overrides

#### `prices`

The planner may accept hourly or slot-based prices.

Suggested fields:

- `currency`
- `unit`
- `import`
- `export`

Each import/export series should support a slot model like:

```json
{
  "start": "2026-04-28T00:00:00+02:00",
  "end": "2026-04-28T01:00:00+02:00",
  "value": 0.22
}
```

#### `battery`

Suggested fields:

- `soc`
- `min_soc`
- `max_soc`
- `capacity_wh`
- `max_charge_power`
- `max_discharge_power`
- `round_trip_efficiency`

#### `site`

Suggested fields:

- `load_forecast`
- `pv_forecast`
- `baseline_consumption`
- `meter_target_w`

The site section may be partially populated. Missing forecasts are allowed if the selected strategy can operate without them.

#### `strategy`

Suggested fields:

- `goal`
  - examples:
    - `self_consumption`
    - `arbitrage`
    - `peak_shaving`
    - `netzero_bias`
- `weights`
- `bias_target_w`
- `prefer_battery_reserve`

### Planner API Response Shape

The response body should contain:

- `success`
- `plan`
- `resolved`
- `explanations`
- `meta`

#### `success`

- Type: boolean

#### `plan`

The planner-native schedule object.

Suggested shape:

```json
{
  "slots": [
    {
      "start": "2026-04-28T15:00:00+02:00",
      "end": "2026-04-28T20:00:00+02:00",
      "mode": "netzero",
      "target_power": null,
      "min_power": -700,
      "max_power": -100,
      "conditions": [
        {
          "field": "battery_soc",
          "op": ">=",
          "value": 60
        }
      ],
      "fallback": 0,
      "reason": "export while prices are high but preserve reserve floor"
    }
  ]
}
```

#### `plan.slots[]`

Each slot should support:

- `start`
- `end`
- `mode`
- `target_power`
- `min_power`
- `max_power`
- `conditions`
- `fallback`
- `reason`

Field semantics:

- `start`
  - ISO-8601 timestamp with timezone
- `end`
  - ISO-8601 timestamp with timezone
- `mode`
  - one of:
    - `fixed`
    - `netzero`
    - `netzero+`
    - `netzero-`
- `target_power`
  - integer watts for fixed mode
  - `null` permitted for dynamic modes
- `min_power`
  - optional integer lower bound
- `max_power`
  - optional integer upper bound
- `conditions`
  - planner-native runtime conditions or decision conditions
- `fallback`
  - fallback target or mode if conditions do not hold
- `reason`
  - short human-readable explanation for the slot

#### `resolved`

This field must contain the compatibility schedule derived from `plan.slots[]`.

The planner API should treat `resolved` as the canonical adapter output for the current automation consumer.

#### `explanations`

Optional human-readable rationale list.

Suggested shape:

```json
[
  "Charged during low import prices between 02:00 and 05:00.",
  "Discharge window limited by reserve floor and max export bound.",
  "Runtime SoC condition applied to preserve evening reserve."
]
```

#### `meta`

Suggested fields:

- `planner_version`
- `generated_at`
- `input_summary`
- `warnings`
- `request_id`

### Planner API Error Contract

Planner-level failures should use the same application JSON shape as the compatibility API:

```json
{
  "success": false,
  "error": "human-readable explanation"
}
```

---

## Mapping Rules: Planner API to Compatibility API

If the planner API is used, the compatibility payload must be derived deterministically from the planner output.

### Required Mapping Rules

- planner `slot.start` becomes compatibility `time`
  - convert to local `Europe/Amsterdam`
  - serialize as `HHMM`
- planner `mode` and `target_power` become compatibility `value`
  - `fixed` mode maps to the integer `target_power`
  - `netzero` maps to `"netzero"`
  - `netzero+` maps to `"netzero+"`
  - `netzero-` maps to `"netzero-"`
- planner `min_power` passes through to compatibility `min_power`
- planner `max_power` passes through to compatibility `max_power`
- planner battery SoC conditions map to compatibility `runtime_conditions`
  - planner field `battery_soc` must become compatibility field `electricity_level`
- planner `fallback` maps to compatibility `fallback_value`
- planner-native explanatory fields do not flow into the Python runtime except as optional metadata

### Overlap Resolution Rule

If the planner emits overlapping slots, the adapter must resolve them **before** publishing the compatibility payload.

The compatibility payload must not contain ambiguous overlapping effective times. It must be a monotonic sequence of effective slots for the current local date.

### Example Mapping

Planner slot:

```json
{
  "start": "2026-04-28T15:00:00+02:00",
  "end": "2026-04-28T20:00:00+02:00",
  "mode": "netzero",
  "target_power": null,
  "min_power": -700,
  "max_power": -100,
  "conditions": [
    {
      "field": "battery_soc",
      "op": ">=",
      "value": 60
    }
  ],
  "fallback": 0,
  "reason": "Export only when reserve remains healthy"
}
```

Compatibility slot:

```json
{
  "time": "1500",
  "value": "netzero",
  "min_power": -700,
  "max_power": -100,
  "runtime_conditions": [
    {
      "field": "electricity_level",
      "op": ">=",
      "value": 60
    }
  ],
  "fallback_value": 0
}
```

---

## Validation Rules

The producer must validate all published compatibility payloads.

### Time Validation

- `time` must be valid `HHMM`
- hours must be `00..23`
- minutes must be `00..59`

### Ordering Validation

- `resolved` must be monotonic in ascending `time`
- duplicate effective slots should be avoided unless the producer has a documented reason to preserve them

### Value Validation

- `value` must be one of:
  - integer watts
  - `"netzero"`
  - `"netzero+"`
  - `"netzero-"`

### Bound Validation

- `min_power` must be integer-only when present
- `max_power` must be integer-only when present
- if both are present, `min_power <= max_power`
- bounds are only valid for dynamic modes

### Runtime Condition Validation

- only `electricity_level` is supported as the compatibility runtime condition field
- only `>`, `>=`, `<`, `<=`, `==`, `!=` are supported operators
- `value` must be numeric

### Fallback Validation

- `fallback_value` must be either:
  - integer watts
  - `"netzero"`
  - `"netzero+"`
  - `"netzero-"`

### Schedule Coverage Validation

- the response must contain a baseline slot effective from `0000` or earlier
- the producer must publish the current local date’s resolved schedule
- the producer must not publish overlapping unresolved planner slots into the compatibility payload

---

## Consumer Behavior Notes

The current automation runtime behaves as follows:

- schedule refresh cadence is periodic, not push-based
- the runtime stores a flat resolved list and selects the latest slot with `time <= current HHMM`
- live battery checks are applied separately at runtime
- dynamic values may be clamped further by:
  - battery SoC safety logic
  - charge/discharge power caps
  - power-feed delta safeguards
  - reversal-ramp safeguards
- `runtime_conditions` are evaluated in Python using live device state
- the runtime can effectively degrade `None` or a missing active slot to `0`, but producers should avoid relying on that behavior

The schedule agent should therefore produce a complete, explicit, and self-consistent resolved schedule rather than depending on runtime fallbacks.

---

## Compliance Scenarios

An implementation should be considered compliant if all of the following scenarios work as specified.

### 1. Valid Compatibility Response

- the compatibility endpoint returns `success: true`
- the payload contains a flat `resolved` array
- the array represents the current Amsterdam-local day

### 2. Current Slot Selection

- for a mid-day timestamp such as `15:15`, the runtime selects the slot with the largest `time <= 1515`

### 3. Dynamic Mode Bounds

- a compatibility slot with `value: "netzero"` and both `min_power` and `max_power` causes the runtime to clamp the dynamic watt calculation correctly

### 4. Runtime Fallback Behavior

- when `runtime_conditions` evaluate false, the runtime applies `fallback_value`

### 5. Midnight Coverage

- the published schedule includes a baseline slot at `0000` or earlier
- after midnight rollover, the runtime still has a valid current slot

### 6. Invalid Bound Rejection

- a payload where `min_power > max_power` is rejected by the producer contract and not published as valid compatibility output

### 7. Unsupported Runtime Condition Rejection

- a payload using an unsupported compatibility runtime condition field is rejected by the producer contract

### 8. Deterministic Planner Translation

- a valid planner response can be translated deterministically into a valid compatibility response
- translation preserves mode, bounds, SoC conditions, and fallback semantics

---

## Implementation Notes for Future Engineers

- Treat the compatibility endpoint as the hard contract for existing automation.
- Treat the planner endpoint as an internal or higher-level contract that may evolve.
- If both are implemented, generate the compatibility payload from the planner output in one place to avoid drift.
- Do not require code changes in the current Python automation runtime to adopt the compatibility endpoint defined here.
