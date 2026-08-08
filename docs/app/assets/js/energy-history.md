# Battery energy history summary

## Purpose

Render the `/app` four-day battery energy chart and the selected-day summary cards, including consumer and spot money totals in tooltips.

## Location

- `app/assets/js/energy-history.js`
- `main/includes/app_energy_history.php`
- `main/api/app_energy_history.php`
- `app/index.php` (Battery energy section)

## Inputs / outputs

- Input: `../main/api/app_energy_history.php?days=3`
- Output cards: Charged, Discharged, PnL
- Each card tooltip shows Consumer and Spot euro totals for the selected day

## Flow / behavior

1. Load hourly energy and per-day `priceTotals`.
2. Charged and Discharged energy are absolute Wh totals for the selected day.
3. The PnL card energy value remains physical net: `chargedWh - dischargedWh`.
4. Money PnL is `dischargedEur - chargedEur` for both consumer and spot.
5. When charge happened at a negative spot price, charged spot euros can be negative; that increases PnL.

## Edge cases / failure modes

- When price data is missing for any hour with energy flow, then that direction’s euro total and PnL become unavailable (`—`).
- When today is selected, totals cover available hours through now.

## Related files

- `docs/app/gui-overview.md`
- `tools/tests/test_app_energy_history.py`
