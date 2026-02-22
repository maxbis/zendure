---
name: weighted-solutions
description: Proposes multiple solution options with explicitly weighted added complexity (implementation, maintenance, testing, dependencies). Use when the user asks for solutions, approaches, implementation options, or when comparing ways to solve a problem.
---

# Weighted Solutions

When proposing solutions, **always offer a series of options** and **explicitly weight the added complexity** of each so the user can choose with full context.

## When to Apply

- User asks "how can I...", "what's the best way to...", or "how should I implement..."
- User wants to compare approaches or choose between options
- User mentions trade-offs, simplicity, or complexity
- Design or architecture decisions are being discussed

## Core Rules

1. **Propose 2–4 distinct options** when the problem admits more than one reasonable approach. If there is effectively only one path, say so and still note its complexity.
2. **For each option, state added complexity explicitly** using the dimensions below.
3. **Use a consistent complexity scale** (e.g. Low / Medium / High) so options are comparable.
4. **Do not recommend a single option by default** unless the user asks for one; present the set and let the user decide based on their constraints.

## Complexity Dimensions

Weight each solution on (at least) these axes. Adapt or add dimensions when relevant (e.g. security, performance, team familiarity).

| Dimension | What to consider |
|-----------|------------------|
| **Implementation** | New code surface, refactor size, one-off vs reusable |
| **Dependencies** | New libs/services, upgrade impact, vendor lock-in |
| **Testing** | Unit/integration/e2e effort, mocking, flakiness |
| **Maintenance** | Ongoing changes, debugging difficulty, docs/runbooks |
| **Learning / onboarding** | Team familiarity, conceptual load, docs availability |

Keep descriptions **short** (one line per dimension when possible). Use **Low / Medium / High** (or **L/M/H**) for quick scanning; add one sentence of justification only where it matters.

## Output Format

### Option list

For each option:

1. **Option name** (1 short line)
2. **Brief description** (1–3 sentences)
3. **Added complexity**
   - Implementation: [L/M/H] — [one-line reason]
   - Dependencies: [L/M/H] — [one-line reason]
   - Testing: [L/M/H] — [one-line reason]
   - Maintenance: [L/M/H] — [one-line reason]
   - (Other dimensions if relevant)
4. **Best for** (when this option is a good fit)

### Optional summary table

When there are 3+ options, add a small comparison table:

| Option | Impl | Deps | Test | Maint | Best for |
|--------|------|------|------|-------|----------|
| A      | L    | L    | M    | L     | Quick win, low risk |
| B      | M    | H    | H    | M     | Scale, future-proof |
| ...    | ...  | ...  | ...  | ...   | ... |

## Example (condensed)

**Option 1: Inline script in the existing page**  
Run a one-off script in the browser console to fix data.  
- Implementation: **Low** — few lines, no new files  
- Dependencies: **Low** — none  
- Testing: **Low** — manual run once  
- Maintenance: **Low** — no ongoing code  
- **Best for:** One-time fix, no deployment

**Option 2: New backend endpoint + cron**  
Add an API route and a cron job to correct data periodically.  
- Implementation: **Medium** — new route, cron config, error handling  
- Dependencies: **Low** — existing stack  
- Testing: **Medium** — unit + one integration check  
- Maintenance: **Medium** — monitor cron, handle failures  
- **Best for:** Recurring correction, audit trail

**Option 3: Dedicated worker service**  
New service that consumes a queue and applies corrections.  
- Implementation: **High** — new service, queue, deployment pipeline  
- Dependencies: **High** — queue (e.g. Redis/SQS), new runtime  
- Testing: **High** — service + integration + e2e  
- Maintenance: **High** — ops, scaling, observability  
- **Best for:** High volume, reliability, and decoupling

## Summary Checklist

When using this skill, ensure:

- [ ] Multiple options are proposed (or explicit statement that only one path exists)
- [ ] Each option has complexity stated on the same dimensions
- [ ] Scale (L/M/H or Low/Medium/High) is consistent
- [ ] "Best for" is stated so the user can match to their context
- [ ] Optional table is used when 3+ options
