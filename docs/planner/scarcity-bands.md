# Scarcity bands

## Purpose

The scarcity-band algorithm prevents a rolling optimizer with a limited price horizon from treating all stored battery energy as equally expendable.

The motivating case is a low-solar period in which the visible horizon contains a profitable discharge price, but the battery may be difficult to refill before an even more expensive period beyond that horizon. Scarcity bands assign an additional virtual value to deeper battery energy when forecast refill is limited.

This replaces the former single low-SoC threshold and fixed low-SoC discharge cost. The configured `minChargePercent` remains the hard lower limit. Scarcity bands are soft limits: sufficiently valuable discharge can still use them.

Scarcity values affect optimizer decisions only. They are not electricity charges, revenues, or cash P&L.

## Location

- Calculation and historical-price loading: [`../../planner/scarcity.py`](../../planner/scarcity.py)
- Optimizer cost application: [`../../planner/rolling_optimizer.py`](../../planner/rolling_optimizer.py)
- Runner and published payload: [`../../planner/shadow.py`](../../planner/shadow.py)
- Shared configuration: `common/config/system.json`
- Shared JSON Schema: [`../../common/config/system.schema.json`](../../common/config/system.schema.json)
- Python configuration loader: [`../../common/python/system_config.py`](../../common/python/system_config.py)
- PHP configuration loader: [`../../common/php/system_config.php`](../../common/php/system_config.php)
- Read-only API: [`../../app/api/optimizer_scarcity.php`](../../app/api/optimizer_scarcity.php)
- Diagnostics page: [`../../app/optimizer_scarcity.php`](../../app/optimizer_scarcity.php)
- Tests: [`../../planner/tests/test_scarcity.py`](../../planner/tests/test_scarcity.py)

## Configuration

The algorithm is configured under `battery.dynamicReserve`:

```json
{
  "battery": {
    "minChargePercent": 15,
    "maxChargePercent": 91,
    "dynamicReserve": {
      "enabled": false,
      "priceHistoryDays": 21,
      "priceHistoryHalfLifeDays": 7,
      "referencePriceCapEurPerKwh": 0.8,
      "cheapGridChargePercentile": 25,
      "bands": [
        {
          "fromSocPercent": 15,
          "toSocPercent": 25,
          "pricePercentile": 95,
          "activateWhenExpectedRefillBelowPercent": 100
        },
        {
          "fromSocPercent": 25,
          "toSocPercent": 40,
          "pricePercentile": 85,
          "activateWhenExpectedRefillBelowPercent": 60
        },
        {
          "fromSocPercent": 40,
          "toSocPercent": 50,
          "pricePercentile": 75,
          "activateWhenExpectedRefillBelowPercent": 25
        }
      ]
    }
  }
}
```

### Master switch

- `enabled: true` means active bands add their scarcity cost to optimizer discharge candidates.
- `enabled: false` means no scarcity cost affects optimizer decisions.
- When disabled, the planner still calculates and publishes the history, refill, band activation, and additional scarcity values. This supports shadow evaluation before activation.
- A diagnostically active band has `active: true`. It has `applied: true` only when the master switch is also enabled.

### Historical-price settings

- `priceHistoryDays` selects the number of completed local calendar days requested for the historical distribution.
- `priceHistoryHalfLifeDays` controls recency weighting. With a value of 7, a price seven days old has half the weight of the newest completed day.
- `referencePriceCapEurPerKwh` caps every percentile reference price before the additional scarcity value is calculated. It prevents a small number of extreme historical prices from assigning an excessive value to stored energy.
- `cheapGridChargePercentile` defines which visible import-price slots count as potential cheap-grid refill. A value of 25 uses the weighted 25th-percentile historical consumer price.

The historical weight is:

```text
weight = 0.5 ^ (age in days / priceHistoryHalfLifeDays)
```

The percentile calculation sorts prices from low to high and returns the first price whose cumulative weight reaches the requested percentile.

### Band settings

Each band contains:

- `fromSocPercent`: lower edge of the SoC band.
- `toSocPercent`: upper edge of the SoC band.
- `pricePercentile`: weighted historical consumer-price percentile used to value energy in the band.
- `activateWhenExpectedRefillBelowPercent`: the band is active when predicted refill coverage is strictly below this percentage.

Deeper energy uses a higher price percentile. In the example:

- Energy from 15% through 25% uses P95 and activates below 100% refill coverage.
- Energy from 25% through 40% uses P85 and activates below 60% refill coverage.
- Energy from 40% through 50% uses P75 and activates below 25% refill coverage.
- Energy above 50% has no scarcity band and follows the normal optimizer objective.

The strict loaders require:

- The first band starts at `minChargePercent`.
- Every `fromSocPercent` is lower than its `toSocPercent`.
- Adjacent bands are contiguous, without gaps or overlaps.
- Band limits stay between `minChargePercent` and `maxChargePercent`.
- Price percentiles decrease as the bands move upward.
- Refill activation boundaries decrease as the bands move upward.

The old `lowSocDischargeThresholdPercent` and `lowSocDischargeCostCentsPerKwh` properties are no longer accepted.

## Inputs/Outputs

### Inputs

The calculation receives:

- Current battery SoC and usable capacity.
- Configured minimum and maximum SoC.
- Maximum charge power.
- Round-trip efficiency.
- Optimizer slots with duration, consumer price, forecast PV, and forecast household load.
- The normal terminal price used by the optimizer, calculated as the mean consumer import price across the current optimization horizon.
- Completed historical consumer prices.
- The `dynamicReserve` configuration.

### Historical price sources

The planner tries sources in this order:

1. MariaDB `price_ticks`, using connection values from `daily_report/.env` and the `MARIADB_*` environment variables.
2. Local JSON files under `main/data/price/YYYYMM/priceYYYYMMDD.json`.

The database source is used only when it supplies at least 168 valid hourly values. Otherwise the planner tries JSON files. Current-day and future prices are not included in the historical distribution; the last eligible day is yesterday in the installation timezone.

### Published output

The optimizer plan publishes a `scarcity` object containing:

- `algorithm_enabled`
- `calculation_available`
- `unavailable_reason`
- `calculated_at`
- `normal_terminal_price_eur_per_kwh`
- `cheap_grid_charge_price_eur_per_kwh`
- `price_history`
- `refill`
- `bands`

Each optimizer decision also publishes `expected_scarcity_cost_eur`. The plan publishes the total as `expected_scarcity_cost_eur`.

## Flow/Behavior

### 1. Load and weight historical prices

The planner requests the configured history ending yesterday. It ignores invalid, missing, Boolean, and non-finite values.

At least 168 valid hourly values are required. This is a minimum sample count, not a requirement that the values come from exactly seven consecutive days.

### 2. Establish price references

The planner calculates:

- The cheap-grid charging boundary from `cheapGridChargePercentile`.
- A reference price for each band from its configured `pricePercentile`.
- The band reference after applying `referencePriceCapEurPerKwh`.

The additional scarcity value is:

```text
additional scarcity value =
    max(0, capped band reference price - normal terminal price)
```

Only the difference is added because the normal terminal price already values energy retained at the end of the horizon. Adding the full percentile price would count part of the stored-energy value twice.

### 3. Calculate required refill

Required refill is the internal battery energy needed to move from current SoC to `maxChargePercent`:

```text
required refill Wh =
    max(0, energy at maximum SoC - current battery energy)
```

### 4. Calculate forecast solar refill

For every optimizer slot:

```text
forecast solar surplus W = max(0, forecast PV W - forecast load W)
```

The contribution is limited by:

- Maximum charge power.
- Slot duration.
- Charge efficiency, calculated as the square root of round-trip efficiency.
- Remaining energy needed to reach maximum SoC.

### 5. Calculate cheap-grid refill

A slot counts as a cheap-grid opportunity when:

```text
slot import price <= cheap-grid charging boundary
```

Available grid charging uses the charging power left after forecast solar charging in the same slot. This prevents solar and grid refill from double-counting the same charge-power capacity.

The refill calculation measures available opportunities. It is not a claim that the final optimizer schedule will use every opportunity.

### 6. Calculate refill coverage

```text
expected refill factor =
    min(1, predicted total refill Wh / required refill Wh)
```

```text
expected refill percent = expected refill factor * 100
```

When the battery is already at its configured maximum SoC, the factor is 1 and the percentage is 100%.

The refill percentage is not predicted ending SoC. It describes the percentage of the currently missing energy that can be restored. For example:

- Current SoC is 60%.
- Maximum SoC is 91%.
- Predicted refill covers 92% of the missing 31 percentage points.
- Predicted ending SoC is approximately 88.5%, not 92%.

### 7. Activate bands

The activation rule is strict:

```text
active = expected refill percent < activateWhenExpectedRefillBelowPercent
```

The application rule is:

```text
applied = dynamicReserve.enabled AND active
```

Examples:

- When refill is 110%, it is capped at 100%; none of the example bands is active because the deepest band requires a value below 100%.
- When refill is 92%, the 15–25% band is active, while the 25–40% and 40–50% bands are inactive.
- When refill is 45%, the 15–25% and 25–40% bands are active.
- When refill is 20%, all three example bands are active.

### 8. Apply virtual discharge cost

For each discharge candidate, the optimizer determines how much internal battery energy crosses every applied band. It converts that energy to deliverable AC energy using discharge efficiency.

For each crossed band:

```text
band scarcity cost =
    AC kWh discharged from the band
    * additional scarcity value EUR/kWh
```

The optimizer candidate objective becomes:

```text
candidate cost =
    grid energy cost
    + battery wear cost
    + scarcity cost
```

The complete plan objective remains:

```text
objective =
    energy cost
    + battery wear cost
    + scarcity cost
    - terminal energy value
```

A discharge crossing multiple bands pays the corresponding marginal cost for the portion inside each band. Charging and idle actions do not incur scarcity cost.

## API and diagnostics

The authenticated endpoint is:

```text
GET /zendure/app/api/optimizer_scarcity.php
```

The endpoint reads the latest published optimizer plan. It does not recalculate percentiles, refill, or bands in PHP. It additionally reports plan age and whether the published plan is stale.

The authenticated diagnostics page is:

```text
/zendure/app/optimizer_scarcity.php
```

The page explains:

- Whether the master switch is enabled.
- Expected refill coverage and its components.
- Historical source, sample count, period, and weighting.
- Normal terminal and cheap-grid reference prices.
- Every band’s reference price and additional scarcity value.
- Whether each band is active and whether it is applied.

## Edge Cases/Failure Modes

- When the normal terminal price is missing, then `calculation_available` is false and `unavailable_reason` is `terminal_price_unavailable`.
- When fewer than 168 valid historical prices are available from both supported sources, then `calculation_available` is false and `unavailable_reason` is `insufficient_price_history`.
- When the calculation is unavailable, then no bands or scarcity costs are applied and the main optimizer can still produce a schedule.
- When the database connection or query fails, then the planner silently tries the JSON price-file source.
- When the master switch is disabled, then active bands remain visible for diagnostics but `applied` is false.
- When a band reference is below the normal terminal price, then its additional scarcity value is zero.
- When predicted refill exceeds required refill, then total refill and refill coverage are capped at the required amount and 100%.
- When solar and cheap-grid charging occur in the same slot, then their combined input power cannot exceed maximum charge power.
- When a discharge stays above all applied bands, then scarcity cost is zero.
- When a discharge crosses only part of a band, then only that portion receives the band’s scarcity cost.
- When the API reads a plan created before scarcity support, then it returns `not_calculated`.
- When the published optimizer plan is older than the configured maximum age, then the API reports it as stale.

## Operational procedure

1. Keep `dynamicReserve.enabled` false while validating diagnostics.
2. Run the optimizer and inspect the API or diagnostics page.
3. Confirm historical sample count, price references, refill components, and active bands are plausible across different weather and price conditions.
4. Enable the algorithm only after shadow observations are satisfactory.
5. Run the optimizer again; only then will active bands report `applied: true` and influence new decisions.
6. To roll back schedule influence without removing diagnostics, set `dynamicReserve.enabled` to false and run the optimizer again.

## Related Files

- [`rolling-optimizer-shadow.md`](rolling-optimizer-shadow.md)
- [`optimizer-business-rules.md`](optimizer-business-rules.md)
- [`../common/config/system-configuration.md`](../common/config/system-configuration.md)
- [`../app/api/optimizer-scarcity.md`](../app/api/optimizer-scarcity.md)
- [`../app/optimizer-scarcity.md`](../app/optimizer-scarcity.md)
