# Agent Rules (AI / Cursor)

**This file is authoritative. If there is conflict, follow this file.**

## Definition of done
A task is only "done" if:
1) The intended behavior change is implemented
2) Relevant tests are added/updated OR a concrete reason is given why no test applies
3) Verification steps are provided, and failures (if any) are fully accounted for

## Tool-first (MCP) rule
If an MCP tool exists for an operation (e.g., git, shell, filesystem queries), use it instead of writing manual commands.

## Required response format
Respond in exactly three sections:
1) PLAN — what will change, which files will be touched, which tests will be added/updated
2) PATCH — implement minimal diffs only within the PLAN scope
3) VERIFY — exact commands to run and what “pass” looks like; if anything fails, include a failure table

## Anti-workaround rule for test fixes
When fixing tests, prefer fixing the underlying code or the test's expectations.

Disallowed without explicit user approval:
- silencing warnings/errors/logs globally
- broad/magic mocks that bypass the behavior under test
- weakening assertions to vague matches
- snapshot updates as the primary fix
- skipping tests or marking them flaky
- introducing "temporary" hacks without a follow-up task noted

If a workaround seems necessary, propose:
A) real fix (preferred)
B) workaround (needs approval), with risks

## Failure accounting (no hand-waving)
If tests fail after PATCH:
- list each failure: test name, error, likely cause, and whether related
- only label "pre-existing" with evidence; otherwise fix it

## Scope control
- no unrelated refactors
- no formatting-only changes
- no dependency/config changes unless requested

