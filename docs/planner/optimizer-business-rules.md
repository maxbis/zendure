# Battery optimizer: business rules

## Purpose

The optimizer chooses when to charge, discharge, or leave the battery idle to
improve the expected financial outcome over the **whole planning period**. It
starts with the current battery charge and looks at least 24 hours ahead,
normally continuing to the next local midnight. A decision for one hour may
therefore make sense only in light of a later price, solar forecast, or desired
ending battery charge.

## What the optimizer considers

- The current battery charge and the allowed minimum and maximum charge levels.
- The maximum charging and discharging power, and the available power steps.
- Hourly electricity purchase prices and prices received for export.
- Forecast household consumption and solar production.
- Energy lost while charging and discharging the battery.
- A small allowance for battery wear when discharging.
- An extra incentive to preserve charge when the battery is relatively low.

Where a future electricity price has not been published, the optimizer
temporarily uses today's price for the same clock hour. It recalculates when
official prices become available. Forecasts and substitute prices are
assumptions, so the realized outcome can differ from the estimate.

## How it chooses a plan

For each part of the planning period, the optimizer considers feasible charging,
idle, and discharging choices. It compares complete sequences of choices, rather
than deciding each hour independently. A sequence must keep the battery within
its allowed charge range and respect the applicable power limits.

The preferred sequence has the lowest combined expected value of:

1. Electricity purchased, less income from exported electricity.
2. Battery wear from discharge.
3. An additional *virtual* cost for discharge below the low-charge threshold.
4. Less the estimated value of usable energy left in the battery at the end.

The remaining-energy value prevents the optimizer from treating an almost empty
battery at the planning boundary as automatically better. It values energy above
the minimum charge level using the average purchase price during the planning
period, adjusted for the energy that can actually be delivered after losses.
This is an estimated future value, not cash already earned.

Battery wear and the low-charge cost influence the choice of plan. They are not
electricity charges on the bill. The low-charge threshold is a **soft reserve**:
discharging below it remains possible when the expected benefit outweighs its
extra cost. The minimum allowed charge level is a hard limit.

## What the hourly decisions mean

- **Fixed charging:** Charge at a selected power when buying energy now, or
  using available solar energy now, is expected to improve the whole plan.
- **Solar-following charging (`netzero+`):** Charge from actual solar surplus,
  up to the allowed charging limit. The optimizer can choose this when storing
  surplus for later use is worth more than exporting it now, after expected
  losses and wear. Actual surplus may be higher or lower than forecast.
- **Load-following discharging (`netzero-`):** Discharge to offset actual
  household imports, without deliberately exporting battery energy. Its allowed
  discharge can grow during an especially expensive hour, provided enough
  battery energy and discharge power remain.
- **Bidirectional solar and load following (`netzero`):** Absorb solar surplus or
  offset household imports. An initially charge-only solar decision can also
  allow discharge when the current purchase price is high relative to the
  remaining prices and the battery has usable energy.
- **Fixed discharging:** Discharge at a selected power, which may exceed
  household demand and export the excess when that improves the expected plan.
- **Idle:** Neither charge nor discharge when the other choices do not improve
  the expected overall result.

For adaptive discharge, prices at or below the median remaining price do not
increase the modeled discharge limit. Above that median, the permitted limit
increases gradually toward the largest safe discharge at the highest remaining
price. Bidirectional solar and load following begins to allow discharge once
that price-based increase reaches half its full range. Actual battery power can
still differ from the forecast because these commands follow real household
load and solar production. The displayed financial and charge forecasts remain
based on the modeled power, not the full adaptive limit.

## Safeguards and priority

- Battery charge and discharge limits, the minimum and maximum charge levels,
  and the time left in an hour constrain each decision.
- Exact dated manual schedule entries take priority over optimizer decisions.
- An optimizer plan is used only when optimizer mode is selected and the plan
  passes checks for age, coverage, continuity, supported actions, and limits.
  Otherwise, the established rules schedule is used.
- If active-hour stabilization is enabled, a small change to the current
  hour's command can be ignored to avoid unnecessary switching, but only while
  the retained command and resulting trajectory remain safe. This does not
  affect later hours. It is currently disabled.

## How to interpret a surprising decision

- **Charging during a costly hour** can be rational if later energy is expected
  to be even more valuable, or if solar energy can be stored instead of exported.
- **Keeping charge through a costly hour** can preserve energy for a still more
  valuable hour or avoid the extra cost of discharging below the soft reserve.
- **Ending with energy in the battery** reflects the estimated value assigned
  to that remaining energy; the optimizer is not required to empty the battery
  at the planning boundary.
- **A command allowing more power than the forecast shows** gives the battery
  room to react to actual consumption or solar production. The forecast uses
  the modeled action, while the operating command observes its safety bounds.
