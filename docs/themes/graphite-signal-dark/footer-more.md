# Graphite More footer menu

## Purpose

Shared expandable footer menu used by the new GUI (`/app`) and old GUI (`/main`) so both interfaces expose the same navigation chrome. The collapsed bar shows a More control; expanding it reveals menu items that can grow over time.

## Location

- Partial: `themes/graphite-signal-dark/partials/footer-more.php`
- Styles: `themes/graphite-signal-dark/assets/css/components.css` (`.gsd-footer-more*`)
- Behavior: `themes/graphite-signal-dark/assets/js/graphite-controls.js` (`GraphiteFooterMore`)
- Hosts:
  - `app/index.php`
  - `main/charge_schedule_mobile.php`

## Inputs and outputs

Inputs before including the partial:

- `$gsdFooterMoreItems` — required array of menu entries
  - `href` — destination URL
  - `label` — primary text
  - `description` — optional supporting text
  - `icon` — optional sprite symbol id (default `bidirectional`)
- `$gsdFooterMoreSpriteHref` — optional path to `sprite.svg`
- `$gsdFooterMorePanelId` — optional panel element id

Outputs:

- Fixed footer with a More toggle
- Expandable panel listing the configured items
- `GraphiteFooterMore` open/close helpers on `window`

Current host items:

- New GUI → Old GUI (`../main/`)
- Old GUI → New GUI (`../app/`)

## Flow and behavior

1. Page loads Graphite `theme.css`, `components.css`, and `graphite-controls.js`.
2. Host sets `$gsdFooterMoreItems` and includes the partial after main content.
3. On DOM ready, `GraphiteFooterMore.init()` binds the More toggle.
4. Tapping More expands the panel above the bar.
5. Escape or the transparent backdrop closes the panel.
6. Choosing an item navigates to the linked GUI or future destination.

## Edge cases and failure modes

- When `$gsdFooterMoreItems` is missing or empty, then the partial renders nothing.
- When an item lacks `href` or `label`, then that item is skipped.
- When Graphite CSS/JS is not loaded on a host page, then the footer markup may appear unstyled or non-interactive.
- When more items are added later, then only the items array needs updating; the panel layout already supports multiple rows.

## Related files

- [Old and new GUI overview](../../app/gui-overview.md)
- [Graphite Signal Dark README](../../../themes/graphite-signal-dark/README.md)
- [Graphite style guide](../../../themes/graphite-signal-dark/style-guide.md)
