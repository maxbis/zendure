---
name: documentation
description: Manage project documentation in docs/ with a mirrored source structure. Use when asked to create, update, reorganize, or validate documentation, or when answering questions specifically about existing docs and their alignment with code.
---

# Documentation Management

All project documentation lives in `docs/` at the project root. Subfolders mirror the source structure for easy discovery.

## Formatting preference

- Do not use markdown tables in docs or responses for this skill.
- Prefer:
  - Bullet lists for simple mappings.
  - `When ... then ...` bullets for rules.
  - Numbered lists for procedures and ordered steps.

## Triggers

Apply this skill when:

- When user asks to create or update documentation, then create or update docs in `docs/` using mirror structure.
- When user creates new functions and refers to docs, then update relevant docs in `docs/` to reflect new code.
- When user asks about documentation or doc/code alignment, then read docs in `docs/`, validate against source, and answer.

## Location and Structure

- **Root**: All docs go in `docs/` (project root)
- **Mirroring**: For `folder/subfolder/file.php`, docs go in `docs/folder/subfolder/`
- **Format**: `.md` only
- **Filenames**: Descriptive, hyphenated (e.g., `auto-update-via-js.md`, `charge-schedule.md`)
- **Granularity**: Prefer feature-level docs; use file-level docs only when one file has standalone behavior worth documenting.

### Path Mapping

- `schedule/charge_schedule.php` -> `docs/schedule/charge-schedule.md`
- `schedule/api/charge_schedule_api.php` -> `docs/schedule/api/charge-schedule-api.md`
- `mobile/` -> `docs/mobile/`

## When Creating Documentation

1. Determine target path from the source file or folder being documented
2. Create `docs/` subfolders to mirror the source structure
3. Use a descriptive hyphenated filename that reflects what the doc describes
4. Write in markdown format

## When Updating Documentation

1. Locate the existing doc in `docs/` using the mirror structure
2. Update content to reflect new functions, behavior, or changes
3. Keep the same file path; do not relocate docs

## When Answering Code Questions

1. Check `docs/` for relevant documentation using the mirror path from source
2. Validate docs against source code before relying on them
3. Treat source code as the system of record when docs differ
4. If docs are missing or stale, call it out explicitly and answer from code
5. Propose a doc update when mismatches are found

## Missing/Stale Documentation Handling

1. If no doc exists for the target source area, note "missing documentation"
2. If doc content conflicts with source, note "stale documentation" with specific mismatch points
3. Answer based on source behavior, not outdated doc statements
4. Suggest the exact doc file path to create/update in `docs/`

## Documentation Content Standard

When creating or substantially updating a doc, include these sections:

1. **Purpose** - What this component/flow does
2. **Location** - Related source paths
3. **Inputs/Outputs** - Main inputs, outputs, and contracts
4. **Flow/Behavior** - Core runtime flow or logic steps
5. **Edge Cases/Failure Modes** - Important exceptions and safeguards
6. **Related Files** - Linked modules, APIs, styles, or scripts

## Example Filenames

- `charge-schedule.md` – charge schedule page/component
- `auto-update-via-js.md` – JS auto-update behavior
- `charge-schedule-api.md` – charge schedule API
- `refresh-functions.md` – mobile refresh functions
