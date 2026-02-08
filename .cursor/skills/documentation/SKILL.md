---
name: documentation
description: Centralizes documentation in docs/ folder with mirror structure. Use when user asks to create or update documentation, when adding functions and referring to docs, or when answering questions about code.
---

# Documentation Management

All project documentation lives in `docs/` at the project root. Subfolders mirror the source structure for easy discovery.

## Triggers

Apply this skill when:

| Trigger | Agent Action |
|---------|--------------|
| User asks to create or update documentation | Create or update docs in `docs/` using mirror structure |
| User creates new functions and refers to docs | Update relevant docs in `docs/` to reflect new code |
| User asks about code | Read docs first in `docs/`, then combine with source code |

## Location and Structure

- **Root**: All docs go in `docs/` (project root)
- **Mirroring**: For `folder/subfolder/file.php`, docs go in `docs/folder/subfolder/`
- **Format**: `.md` only
- **Filenames**: Descriptive, hyphenated (e.g., `auto-update-via-js.md`, `charge-schedule.md`)

### Path Mapping

| Source | Docs |
|--------|------|
| `schedule/charge_schedule.php` | `docs/schedule/charge-schedule.md` |
| `schedule/api/charge_schedule_api.php` | `docs/schedule/api/charge-schedule-api.md` |
| `mobile/` | `docs/mobile/` |

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

1. Check `docs/` for relevant documentation first (use mirror path from source)
2. Read docs and source code together for a complete answer
3. Prefer docs as the entry point; supplement with source when needed

## Example Filenames

- `charge-schedule.md` – charge schedule page/component
- `auto-update-via-js.md` – JS auto-update behavior
- `charge-schedule-api.md` – charge schedule API
- `refresh-functions.md` – mobile refresh functions
