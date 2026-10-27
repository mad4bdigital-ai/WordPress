# Contract — Governed Agent Team Handoff (Phase 4, unapproved)

## Strict separation

Feature 009 P1–P3 produce only a **non-authorizing proposal**. A future execution request requires an independent preflight and the existing Control Plane execution boundary; it never turns a plan's `execution_ready` flag into permission.

```text
Team Plan (read-only, source and context digests)
    -> operator-approved execution request (new identity)
    -> exact snapshot/readiness revalidation
    -> per-step authenticated bound NHI + exact grant intersection
    -> existing WordPress/provider permission checks
    -> impact, approval ticket, policy and budget gates
    -> existing durable execution / idempotency / audit
    -> readback evidence and terminal receipt
```

## Phase 4 gate requirements

- **Binding:** An authorized reviewer selects exact team plan SHA + definition snapshots + Skill/context digests + current Site Profile/build + a distinct valid NHI principal for each executing slot/step.
- **Identity:** Caller/authenticated subject MUST resolve to an enabled NHI; a definition ID is not a login. Subagent delegation creates no subject automatically.
- **Effective authorization:** intersection of signed transport identity, exact site/server/provider/ability grant, policy/WordPress permissions, provider readiness, per-agent environment restrictions, approval and budgets. Explicit deny wins; no wildcard grant.
- **Side effects:** Use existing mutation orchestration with durable idempotency/lease/fencing and real before/after readback where applicable. No ability-name execute-any proxy.
- **Budget and fanout:** Bound total steps, per-step cost, context, retries and aggregate team effect; no implicit recursive agents, cycles, hidden delegates or self-triggered approval.
- **Drift:** On mismatch of plan digest, input/context, source refs, Skill/definition revisions, Site Profile/build, NHI grants, ticket or provider readiness, deny and produce an explainable fresh plan rather than continuing.
- **Recovery:** If the effect is uncertain, use existing durable reconciliation; never retry at-least-once mutations blindly. Kill switch stops future dispatch; receipts remain auditable.
- **Privacy:** No raw token or file secret in plan/context/audit; site/workspace isolation across specialist slots.

## Nonauthorizing read surfaces

- Team-plan output may include suggested responsibility, allowed operation class as **advisory metadata** and which grants would be *required*, but never says a grant exists without separately authenticated readback.
- A status or preview endpoint is not a trusted side effect receipt.
- An Agent Model selection or external tool preference does not dynamically install a runtime, enable network or bypass disabled providers.

## Denials to test

DENY-01 missing/disabled NHI; DENY-02 stale definition or external blob; DENY-03 missing exact grant; DENY-04 Production/environment mismatch; DENY-05 approval expired/replayed; DENY-06 budget exceeded; DENY-07 unknown tool/provider; DENY-08 cross-site context; DENY-09 recursive escalation/cycle; DENY-10 changing plan after approval; DENY-11 conflicting grants/explicit deny; DENY-12 duplicate operation or uncertain prior effect.

## Release boundary

No executing integration is permitted until its **own** code review, exact-head CI, runtime enrollment, staging negative/positive tests, owner approval and separately granted deployment authorization. No GitHub Spec Kit content confers execution rights.
