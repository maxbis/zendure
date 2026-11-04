# Optimizer scarcity diagnostics

## Purpose

Explain the latest refill forecast, historical price references, and scarcity-band state in a readable authenticated page.

## Location

- Page: `app/optimizer_scarcity.php`
- Documentation index and renderer: `app/docs.php`
- Markdown renderer: `app/includes/project_markdown.php`
- Styles: `app/assets/css/optimizer-scarcity.css`
- Documentation styles: `app/assets/css/docs.css`
- Browser logic: `app/assets/js/optimizer-scarcity.js`
- API: `app/api/optimizer_scarcity.php`

## Inputs/Outputs

The page reads the authenticated scarcity API once on load, on manual refresh, and once per minute while the tab is visible. It renders calculation freshness, refill components, price-history metadata, and every calculated band.

## Flow/Behavior

1. Show a loading state while requesting the latest published calculation.
2. Preserve distinct enabled, disabled, unavailable, and stale explanations.
3. Render the refill percentage as a compact progress bar and detailed metrics.
4. Render one card per band with its reference price, additional scarcity value, activation boundary, active state, and applied state.
5. Explain that scarcity values are virtual optimizer costs rather than electricity-bill charges.
6. Link to an authenticated, styled rendering of the canonical algorithm documentation in `docs/planner/scarcity-bands.md`.

The documentation viewer indexes every `.md` file beneath `docs/`. It accepts only existing Markdown paths within that directory, escapes raw HTML, renders a constrained Markdown subset, builds a contents list from headings, and keeps links to other Markdown files inside the same validated viewer. References to executable project source are shown as non-clickable source labels.

## Edge Cases/Failure Modes

- When history is insufficient, show the available and required hourly sample counts.
- When the schedule is stale, keep metrics visible but show a freshness warning.
- When the master switch is off, distinguish diagnostically active bands from applied bands.
- When a refresh fails, show a retry action and an explicit error message.
- When the user is not authenticated, the shared login validator returns the existing access-denied page.
- When a documentation path is invalid, outside `docs/`, missing, or unreadable, the viewer returns a styled error without reading another filesystem location.

## Related Files

- `planner/scarcity.py`
- `planner/rolling_optimizer.py`
- `docs/app/api/optimizer-scarcity.md`
- `docs/planner/rolling-optimizer-shadow.md`
