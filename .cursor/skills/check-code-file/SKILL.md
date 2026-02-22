---
name: check-code-file
description: Runs a structured code check on a given file: dead code, unused variables, syntax and structure, control flow, undefined/uninitialized vars, complexity, resources, error messages, and CSS-specific checks. Use when the user asks to check the code for a file, review file xxx, or audit code in a specific file.
---

# Check Code for File

When the user asks to **check the code for file [xxx]** (or similar), run through the following checklist in order. Report findings by group; mark each group as ✅ (nothing found) or list issues with file location (line/column or symbol) and a short fix suggestion. Don't make any changes, only report.

## 1. Dead & unused code

- [ ] **Dead code** — Code that can never run (e.g. after unconditional return/throw, in unreachable branches).
- [ ] **Unused variables** — Variables, parameters, or imports that are never read or used.

## 2. Syntax & structure

- [ ] **Invalid syntax** — Parse errors, mismatched brackets, missing semicolons (where required).
- [ ] **Unreachable code** — Statements that cannot be executed (e.g. after return/break/continue/throw).
- [ ] **Duplicate arguments** — Same parameter name repeated in a function signature.
- [ ] **Bad indentation** — Inconsistent or misleading indentation (especially in Python or indentation-sensitive languages).
- [ ] **Bad escapes** — Invalid escape sequences in strings (e.g. `\x` without hex digits, invalid Unicode escapes).

## 3. Returns & control flow

- [ ] **Missing return on some paths** — Functions that sometimes return a value and sometimes don’t (or return implicitly None/undefined), so callers get inconsistent types.
- [ ] **Unreachable branches** — Conditional branches that can never run (e.g. redundant conditions).
- [ ] **Redundant else after return** — `else` blocks that follow a branch that always returns; the `else` can be flattened for clarity.

## 4. Undefined & uninitialized

- [ ] **Uninitialized variables** — Variables read before they are assigned (e.g. used in one path but only set in another).
- [ ] **Undefined variables** — Identifiers that are not defined in scope (typos, wrong scope, missing imports).
- [ ] **Undefined array offsets / object properties** — Access to keys or indices that may not exist without checks (where the language allows and it’s a bug risk).

## 5. Complexity & maintainability

- [ ] **Too large functions** — Functions that are very long (e.g. > ~40–50 lines); suggest splitting into smaller, named functions.
- [ ] **Too many nested blocks** — Deep nesting (e.g. 4+ levels of if/loop/try); flag as maintainability risk and suggest early returns or extraction.
- [ ] **Too complex branching** — Many branches or complex conditions (e.g. high cyclomatic complexity); suggest simplification or lookup tables.

## 6. Resources & exceptions

- [ ] **Resources not closed** — File handles, connections, streams, or other resources opened but not closed in all paths (use try/finally or context managers where applicable).
- [ ] **Ignored exceptions during cleanup** — In finally/cleanup code, swallowing or ignoring exceptions without at least logging; can hide real failures.

## 7. Error messages

- [ ] **Non-unique error messages** — Identical or very similar error strings used in different places, making it hard to trace which code path produced the error; suggest unique identifiers or more specific messages.

## 8. CSS-specific (when the file is CSS or contains CSS)

- [ ] **Duplicate classes** — Same class name defined more than once (merge selectors or remove duplicate rules).
- [ ] **Combining/simplifying classes** — Selectors that can be merged (e.g. same rules in multiple classes), or opportunities to use a single class instead of several.
- [ ] **Simplifying CSS structure** — Overly specific selectors, redundant rules, or structure that can be simplified (e.g. fewer nesting levels, fewer overrides).

---

## Output format

For each group, either:

- **✅ [Group name]** — No issues found.  
or  
- **[Group name]**  
  - **Issue:** Short description.  
    **Where:** File, line/symbol.  
    **Suggest:** One-line fix or refactor hint.

At the end, add a **Summary**: total number of issues by group and, if relevant, a single sentence on the highest-priority fix.

## Scope

- **Target:** The file(s) the user named (e.g. “check the code for file xxx” → focus on that file; if they give a path, use it).
- **Language:** Apply only checks that apply to that file type (e.g. no “missing return” in a pure HTML file; no CSS duplicate-class check in a pure JS file). For mixed files (e.g. PHP with inline CSS), run both applicable groups.
