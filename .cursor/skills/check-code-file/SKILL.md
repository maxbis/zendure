---
name: check-code-file
description: Run a structured static-style code check on a named file and report findings by group with location, severity, confidence, and short fix suggestions. Use when the user asks to check/review/audit a specific file (e.g. "check code for file X", "review file Y", "audit this CSS/JS/Python file").
---

# Check Code for File

When the user asks to **check code for file [xxx]** (or similar), perform a read-only review and report findings by group.
Do not modify files.

## Review rules

- Use this execution order:
  1. Syntax & structure
  2. Returns & control flow
  3. Dead/unused + undefined/uninitialized
  4. Resources & exceptions
  5. Error messages
  6. Complexity & maintainability
  7. CSS-specific checks (if applicable)
- Apply a **false-positive guard**: report an issue only when there is concrete in-file evidence.
- Prefer precise locations:
  - First choice: line/column.
  - Fallback: symbol/function/class/selector name.
- Keep checks language-aware:
  - Do not flag optional semicolons in JS/TS unless the project or file clearly requires them.
  - Run only checks relevant to the target file type.
- For very large files, cap output to the top 20 highest-impact findings and state that truncation in Summary.

## Weighted prioritization

Use a weighted score for each issue to rank fixes:

- Severity weight: `P0=4`, `P1=3`, `P2=2`, `P3=1`
- Confidence weight: `high=1.0`, `medium=0.7`, `low=0.4`
- Effort multiplier: `S=1.0`, `M=0.8`, `L=0.6`
- `priority_score = severity_weight * confidence_weight * effort_multiplier`

Interpretation:
- Higher score = fix earlier.
- Prefer high-score + low-effort issues as quick wins.
- If scores tie, prioritize by higher severity, then higher confidence.

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
- [ ] **Duplicate declarations in one block** — Same property repeated within a selector block where earlier values are unintentionally overridden.
- [ ] **Combining/simplifying classes** — Selectors that can be merged (e.g. same rules in multiple classes), or opportunities to use a single class instead of several.
- [ ] **Simplifying CSS structure** — Overly specific selectors, redundant rules, or structure that can be simplified (e.g. fewer nesting levels, fewer overrides).

---

## Output format

For each group, either:

- **✅ [Group name]** — No issues found.  
or  
- **[Group name]**  
  - **Issue:** Short description.  
    **Where:** File, line/column or symbol.  
    **Severity:** P0 (critical), P1 (high), P2 (medium), or P3 (low).  
    **Confidence:** high, medium, or low.  
    **Effort:** S (small), M (medium), or L (large).  
    **Risk type:** runtime, security, data-loss, correctness, or maintainability.  
    **Priority score:** numeric score using the weighted formula.  
    **Suggest:** One-line fix or refactor hint.

At the end, add a **Summary** with:
- total issues by group,
- total by severity (P0/P1/P2/P3),
- top 3 quick wins (highest score with `Effort: S`),
- recommended fix order (top 3 by score),
- note if findings were truncated,
- one sentence on the highest-priority fix.

## Scope

- **Target:** The file(s) the user named (e.g. “check the code for file xxx” → focus on that file; if they give a path, use it).
- **Language:** Apply only checks that apply to that file type (e.g. no “missing return” in a pure HTML file; no CSS duplicate-class check in a pure JS file). For mixed files (e.g. PHP with inline CSS), run both applicable groups.
