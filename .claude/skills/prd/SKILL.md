---
description: Turn a raw task file into a complete, implementation-ready PRD (Product Requirements Document)
argument-hint: <path/to/task.md>
---

# PRD

**Input**: $ARGUMENTS (a task file, or a document already in the conversation)

Produce the Product Requirements Document an implementing agent builds against. It answers *what*, *why*, *for whom*, *how we know it worked*, and *under which rules* — not *how to build it*. That's `/plan`'s job.

Runs before `/plan`.

## Process

1. **Read the task file.** Separate what it states from what it assumes. Identify the problem, the user, and the outcome the requester actually wants — not just the feature they named.

2. **Look at the codebase.** `ls`, `git log --oneline -10`, `CLAUDE.md` / `README.md`, plus the closest existing feature to the one being asked for. Pull out: stack and versions, where this kind of code lives, naming conventions, error handling, test patterns — each with a `file:line` source.

   Empty repo? Then propose those conventions instead. One concrete choice per decision, not a menu.

   **Subagents**: if the recon splits into independent threads — conventions, data layer, tests, external dependencies — dispatch them in parallel and have each return findings with `file:line` refs. Skip this on a small or familiar codebase; one agent reading four files directly beats four agents reporting back.

   **No git repo?** `git init` and commit the current state as a baseline before anything gets written — otherwise there's no diff later showing what the implementation touched. Add a `.gitignore` first if there isn't one, so the baseline commit doesn't swallow `node_modules`, `vendor`, build output, or `.env`. Note in the PRD that the repo was initialized.

3. **Define the product.** Goals and how they're measured, user stories, and numbered functional requirements (`FR-1`, `FR-2`, …), each with a priority (Must / Should / Could) and acceptance criteria someone else can verify. Non-functional requirements get the same treatment (`NFR-1`, …). Every requirement traces to a goal or user story; one that doesn't is scope creep — cut it or justify it.

4. **Find the gaps.** Go through the PRD template below and mark each item known, inferred, or missing. Never ask about something the codebase already answers.

5. **Run the security pass.** Walk all ten OWASP 2025 categories against what's being built — one at a time, no skipping. For each: does this feature touch it? If yes, what's the requirement? The output is a requirement the implementer must satisfy and a test that proves it, not a note saying "be careful with auth". Categories the feature genuinely doesn't touch get marked N/A with a reason.

   Check the code around it too, not just the new code — a feature can be written correctly and still break an existing control by widening a query, reusing a permissive handler, or logging a field that wasn't logged before.

6. **Ask, once.** Batch every question whose wrong answer means rework. Give 2–3 options with a recommendation. Everything else becomes a listed assumption — never a silent one.

7. **Write the PRD** next to the task file, named for the feature itself in kebab-case — `user-invite-flow.prd.md`, not `task.prd.md` or `notes-final-v2.prd.md`. The name should tell someone what's inside without opening it.

## Output

```markdown
# PRD: {Name}

**Source**: `{task file}` · **Type**: {feature/bugfix/refactor} · **Status**: {READY / BLOCKED} · **Date**: {YYYY-MM-DD}

## 1. Overview
{One paragraph: what this is and why it matters now.}

## 2. Problem
{What's broken or missing today, who feels it, and what it costs. Evidence if any exists.}

## 3. Goals & Success Metrics
| Goal | Metric | Target | How it's measured |
|---|---|---|---|

**Non-goals**: {things this deliberately does not try to achieve}

## 4. Users & Personas
{Who they are, their context, and what they can do after this ships that they can't now.}

## 5. User Stories
- **US-1**: As a {user}, I want {capability}, so that {outcome}.

## 6. User Flows
{Main flow step by step, plus alternate and error flows. States the user sees: empty, loading, error, success.}

## 7. Functional Requirements
| ID | Requirement | Priority | Story | Acceptance criteria |
|---|---|---|---|---|
| FR-1 | {The system must…} | Must | US-1 | {Given / When / Then} |

## 8. Non-Functional Requirements
| ID | Category | Requirement | Acceptance criteria |
|---|---|---|---|
| NFR-1 | {Performance / Scale / Accessibility / Reliability / i18n} | | |

## 9. Out of Scope
- {Thing} — {why not / where it belongs}

## 10. Definition of Done
- [ ] Every Must requirement meets its acceptance criteria
- [ ] {Other observable outcome someone else can verify}

## 11. Technical Context
**Stack & areas affected**: {Tech + versions. Which parts of the codebase this touches, directly and indirectly.}

**Conventions**: {Naming, structure, error handling, tests — each with `file:line`, or marked PROPOSED for greenfield. Include a real snippet where it's clearer than prose.}

**Data & contracts**: {Entities, schema changes, API/event shapes, migrations, backwards-compat. Or "None".}

**Constraints**: {Architecture rules that must hold.}

**Dependencies**: {External services, other teams, other features this waits on — or "None".}

## 12. Security (OWASP Top 10:2025)

| # | Category | Applies? | Requirement | How it's tested |
|---|---|---|---|---|
| A01 | Broken Access Control | | | |
| A02 | Security Misconfiguration | | | |
| A03 | Software Supply Chain Failures | | | |
| A04 | Cryptographic Failures | | | |
| A05 | Injection | | | |
| A06 | Insecure Design | | | |
| A07 | Authentication Failures | | | |
| A08 | Software or Data Integrity Failures | | | |
| A09 | Security Logging and Alerting Failures | | | |
| A10 | Mishandling of Exceptional Conditions | | | |

**Existing controls at risk**: {controls this change could weaken, or "None identified"}
**Secrets / PII touched**: {what data, where it flows, how it's stored — or "None"}

## 13. Do / Don't
- Do: {instruction}
- Don't: {instruction} — {consequence}

## 14. Risks & Caveats
| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|

{Sharp edges in the affected code, external dependencies, failure modes.}

## 15. Assumptions
| Assumption | Basis | If wrong |
|---|---|---|

## 16. Open Questions
{Blocking, unanswered — or "None".}

## 17. Tests
{What must be covered: every FR/NFR acceptance criterion, happy path, error cases, boundaries, and every "How it's tested" cell from the security table. Note anything that needs a fixture, seed, or mock that doesn't exist yet.}

## 18. Verification
{Build, lint, test commands. Plus end-to-end checks precise enough to execute.}

## 19. Release Plan
- **Branch**: {new branch + name, or existing branch + which}
- **Milestones**: {what ships first if the work is phased — or "single release"}
- **Migrations / ordering**: {anything that must run before or after deploy}
- **Config / secrets**: {new env vars, where they're set, who sets them}
- **Feature flag**: {name and default, or "none"}
- **Rollout**: {all at once, staged, behind a flag}
- **Rollback**: {how to undo, and whether data changes make it one-way}
- **Post-launch**: {how and when the success metrics in §3 get checked}
```

## Rules

- Explaining *how* to build it means you've drifted into `/plan`.
- Every functional requirement has an ID, a priority, and acceptance criteria. A requirement nobody can verify isn't a requirement.
- Every requirement traces to a user story or goal; every goal has a metric.
- A convention without a `file:line` is either unsourced or PROPOSED. Say which.
- Anything from your head, not the task file, goes in the assumptions table.
- Every OWASP row gets an answer. "Applies: no" is an answer; a blank cell is not.
- A security requirement with no test in the same row is incomplete.
- Open questions remaining means Status is BLOCKED. Don't hand it to `/plan`.
- If the task is really several products or features, or the codebase contradicts it, say so before writing.
