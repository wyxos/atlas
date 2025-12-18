# Agent Rules (AI / Cursor)

**This file is authoritative. If there is conflict, follow this file.**

## Definition of done
A task is only "done" if:
1) The intended behavior change is implemented
2) Relevant tests are added/updated OR a concrete reason is given why no test applies
3) Verification is RUN when MCP/shell tools are available, with raw output included
    - If verification is NOT RUN, the task is not "done"; it is "ready for local verification" only
4) Failures (if any) are fully accounted for

## Observability rule (no silent degradation)
Do not add fallback/default behavior that masks operational failures unless explicitly requested.
If a fallback is needed, it must:
- preserve an explicit error signal (status + error details)
- surface degraded mode to the user and/or telemetry
- provide a retry path

## Product intent protection (no reintroducing removed behavior)
If the user explicitly removed a behavior (e.g., fallbacks/defaults, silencing errors, broad mocks),
the agent must not reintroduce it to make tests pass.
Instead, update tests to match the new intended behavior or ask for clarification if intent is unclear.

## Test-fix scope rule
If the user asks to "fix tests" (or similar), do not change production/runtime behavior to satisfy tests.
Only change tests and test utilities unless the user explicitly approves a production change.

## Tool-first (MCP) rule
If an MCP tool exists for an operation (git/shell/filesystem), the agent must use it.

The agent may only fall back to manual instructions if:
- the tool invocation fails or is unavailable, AND
- the agent includes the tool error/output as evidence.

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

## Verification rule (execution + evidence)
In VERIFY, the agent must:
- RUN the relevant verification via MCP/shell when available
- Paste raw command output as evidence

If verification is NOT RUN, the agent must:
- state NOT RUN + why
- not claim tests are resolved/passing
- provide a minimal local run checklist

The agent must not claim success without stating whether verification was RUN or NOT RUN.

## Evidence rule (no unverifiable claims)
The agent may not claim tests are fixed/passing unless it includes evidence:
- a command transcript/output, or
- the MCP tool output showing success.

If verification was NOT RUN, the agent must not state that failures are resolved; it may only state what it changed and what it expects to happen.

## Failure accounting (no hand-waving)
If tests fail after PATCH:
- list each failure: test name, error, likely cause, and whether related
- only label "pre-existing" with evidence; otherwise fix it

## Scope control
- no unrelated refactors
- no formatting-only changes
- no dependency/config changes unless requested

