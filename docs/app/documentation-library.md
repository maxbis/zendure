# Project documentation library

## Purpose

Provide one authenticated index and HTML renderer for every Markdown document under `docs/`.

The index makes the project documentation browsable without exposing raw executable project files. It automatically includes new Markdown documents, groups them by their top-level folder, and supports client-side title and path filtering.

## Location

- Index and document page: `app/docs.php`
- Standard Markdown renderer: `app/includes/project_markdown.php`
- Styles: `app/assets/css/docs.css`
- Search behavior: `app/assets/js/docs.js`
- Canonical source documents: `docs/**/*.md`

## Inputs/Outputs

The index recursively reads `.md` files below `docs/`. For each document it exposes:

- The first level-one heading as its display title.
- Its top-level folder as its index section.
- Its canonical path below `docs/`.
- A link to the standard rendered view.

The document view accepts a `doc` query parameter containing a relative Markdown path below `docs/`.

```text
GET /zendure/app/docs.php?doc=planner%2Fscarcity-bands.md
```

## Flow/Behavior

1. Authenticate with the shared login validator.
2. Discover and alphabetically index every Markdown file below `docs/`.
3. When no `doc` parameter is supplied, render the complete searchable index.
4. When a valid document is requested, read it and pass it through the shared renderer.
5. Generate an on-page contents list from level-two and level-three headings.
6. Route relative links to other Markdown documents back through the same viewer.
7. Display source-code references as labels instead of linking to executable web paths.

The renderer supports the Markdown constructs currently used by the project documentation:

- Headings and paragraphs.
- Ordered and unordered lists.
- Fenced code blocks and inline code.
- Links to web pages and other project documentation.
- Bold text.
- Blockquotes and horizontal separators.
- Pipe tables.

Raw HTML is escaped rather than executed.

## Edge Cases/Failure Modes

- When a requested path contains unsupported characters or traversal syntax, then the viewer rejects it.
- When a requested path resolves outside `docs/`, then the viewer rejects it.
- When a file is missing or unreadable, then the viewer shows a styled error with a link back to the index.
- When JavaScript is unavailable, then every document remains visible and usable; only live search filtering is unavailable.
- When a relative link targets executable source rather than Markdown, then it is rendered as a non-clickable source reference.

## Related Files

- [`optimizer-scarcity.md`](optimizer-scarcity.md)
- [`../planner/scarcity-bands.md`](../planner/scarcity-bands.md)
- [`../../themes/graphite-signal-dark/README.md`](../../themes/graphite-signal-dark/README.md)
