# Graphite Signal Dark

Graphite Signal Dark is a mobile-first dark interface theme for dense, live
operational dashboards. Graphite surfaces keep the interface quiet; signal
colors are reserved for state, direction, price, warnings, and actions.

The theme is fully isolated in this directory and has no dependency on the
existing Zendure application styles or scripts.

## Contents

- `index.html` — interactive component and dashboard showcase
- `mockup.html` — static redesign concept for the current energy-manager page
- `style-guide.md` — principles, tokens, component rules, and accessibility
- `assets/css/theme.css` — semantic tokens and base theme
- `assets/css/components.css` — reusable controls, dialogs, and flash messages
- `assets/css/demo.css` — showcase-only layout and energy visualizations
- `assets/js/graphite-controls.js` — reusable dialog and flash-message APIs
- `assets/js/demo.js` — showcase interactions
- `assets/js/mockup.js` — mockup-only dialog and preview interactions
- `assets/icons/sprite.svg` — local SVG icon set

## Run the showcase

Serve the repository root, then open:

```text
/themes/graphite-signal-dark/
```

For example:

```sh
php -S 127.0.0.1:8080
```

## Use in another page

Load the theme and controls:

```html
<link rel="stylesheet" href="/themes/graphite-signal-dark/assets/css/theme.css">
<link rel="stylesheet" href="/themes/graphite-signal-dark/assets/css/components.css">
<script src="/themes/graphite-signal-dark/assets/js/graphite-controls.js" defer></script>
```

Use the `gsd-` component classes and place templates with
`data-gsd-dialog-template` in the page. See `index.html` for complete examples.

```js
GraphiteFlash.success("Schedule saved.");
GraphiteDialog.open("schedule-dialog");
```

Public controls:

- `GraphiteDialog.open(id)`
- `GraphiteDialog.close(dialog, returnValue)`
- `GraphiteFlash.show({ type, title, message, duration })`
- `GraphiteFlash.success(message, options)`
- `GraphiteFlash.error(message, options)`
- `GraphiteFlash.warning(message, options)`
- `GraphiteFlash.info(message, options)`
- `GraphiteFlash.dismissAll()`
