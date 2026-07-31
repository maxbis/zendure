# Graphite Signal Dark style guide

## Character

Graphite Signal Dark is a mobile-first system for live, information-dense
utility applications. It should feel:

- Technical without looking industrial.
- Dark without losing surface hierarchy.
- Compact without shrinking touch targets.
- Quiet at rest and vivid only where a signal matters.
- Direct: labels, values, units, and consequences stay visible.

## Principles

1. **Graphite carries structure.** Use changes in surface lightness and borders
   to separate the page, cards, inset regions, and controls.
2. **Color carries meaning.** Blue means interaction or net-zero, green means
   charging/success, red means discharging/error, amber means attention, and
   lime is reserved for price or optimization signals.
3. **State is never color-only.** Pair color with text, an icon, a value, a
   pattern, or a position.
4. **Values lead, labels support.** Make the current state and important number
   easier to scan than its unit and metadata.
5. **Touch comes first.** Interactive targets are at least 40px, preferably
   44px for primary mobile actions.
6. **Motion explains change.** Use short fades, small translations, and progress
   changes. Do not animate large layout reflows.

## Token layers

All public tokens use the `--gsd-` prefix.

### Surfaces

- `--gsd-page`: application canvas
- `--gsd-surface-1`: primary card
- `--gsd-surface-2`: inset group and dialog body
- `--gsd-surface-3`: fields, secondary controls, and elevated regions
- `--gsd-surface-hover`: hover state
- `--gsd-surface-selected`: selected state
- `--gsd-backdrop`: modal backdrop

### Text and boundaries

- `--gsd-text`: primary values and labels
- `--gsd-text-secondary`: descriptions and units
- `--gsd-text-muted`: tertiary metadata
- `--gsd-text-disabled`: disabled controls
- `--gsd-border`: ordinary structural boundary
- `--gsd-border-strong`: interactive and floating boundary

### Signals

- `--gsd-accent`: primary actions, links, focus, net-zero
- `--gsd-positive`: charging and successful outcomes
- `--gsd-negative`: discharging, errors, and destructive actions
- `--gsd-warning`: attention and pending states
- `--gsd-price`: price and optimization emphasis
- Each signal has a `-soft` companion for backgrounds.

## Typography

Use the platform sans-serif stack. Numeric displays use tabular figures.

- Page title: `1.5rem`, weight 700
- Section title: `1.125rem`, weight 650
- Dialog title: `1.125rem`, weight 650
- Prominent metric: `1.75–2rem`, weight 700
- Body and controls: `0.9375rem`
- Supporting text: `0.8125rem`
- Metadata: `0.75rem`

Avoid making every label bold. Reserve weight 700 for current values, totals,
and states that need immediate recognition.

## Spacing and shape

Use a four-pixel rhythm: 4, 8, 12, 16, 20, 24, and 32px.

- Compact controls: 8px radius
- Inputs and inset regions: 10px radius
- Cards: 14px radius
- Dialogs: 18px radius on mobile, 16px on larger screens
- Pills and status dots: fully rounded

Cards use borders before shadows. Floating dialogs use a strong border and one
deep, diffused shadow.

## Buttons and fields

- Primary buttons use the blue accent and bright text.
- Secondary buttons use Surface 3 with a strong border.
- Quiet buttons have transparent backgrounds at rest.
- Destructive buttons use the negative signal only at the point of confirmation.
- All controls have a visible `:focus-visible` ring.
- Disabled controls reduce contrast but keep their label readable.

## Dialogs

Standard anatomy:

1. Header with title and an accessible close control
2. Scrollable body
3. Stable footer with secondary action before the primary action

The supplied energy-slot dialog adds previous/next controls around its title.
Those controls use the same icon-button component as the rest of the system.

Behavior:

- Escape closes the topmost non-blocking dialog.
- Clicking the backdrop closes a non-blocking dialog.
- Focus returns to the opening control.
- The footer stays visible when the body scrolls.
- Destructive confirmation uses `role="alertdialog"`.
- At narrow widths, dialog actions fill available width and may wrap.
- The top-right close control rotates its icon 90 degrees and shifts to the
  negative signal color on hover or keyboard focus. Reduced-motion mode keeps
  the color cue but removes rotation.

## Flash messages

Flash messages appear in a polite live region, top-center on mobile and
top-right on wider screens. Types are success, error, warning, and info.

- Every type includes an icon, title, and readable text.
- Error messages remain until dismissed by default.
- Other messages auto-dismiss but always include a close control.
- A progress line may show remaining time; it is supplementary, not the only
  indication that a message will close.
- The container is limited to four visible messages.

## Accessibility

- Essential normal text must reach 4.5:1 contrast.
- Large text, icons, focus indicators, and meaningful graphics must reach 3:1.
- Status and direction always include text or a second visual cue.
- Icon controls require an accessible name.
- Dialog focus is contained and returned to the trigger on close.
- Touch targets remain at least 40px.
- Reduced-motion preferences disable nonessential movement and countdown
  animation.

## Responsive behavior

- Mobile: one content column, edge-to-edge cards with 12px page padding.
- Tablet: wider cards and two-column component groups where space permits.
- Desktop: a centered dashboard with a stable two-column showcase layout.
- Dialogs remain within `100dvh - 24px`, with scroll confined to their body.
