# Feature 009 — Requirements & Security Checklist

**Current status:** Spec Kit authored; no runtime code implemented. Check marks below are review gates, not automatic certifications.

## Specification consistency

- [ ] C01 Definition, authenticated NHI identity and execution authority have independent identifiers, owners and lifecycle.
- [ ] C02 All scope requirements FR-001..FR-017 have linked implementation tasks and acceptance tests.
- [ ] C03 Portable dialects are explicitly allowlisted/fixture-verified; AGENTS.md project instructions are not automatically an Agent.
- [ ] C04 Existing Skill source reader, NHI registry, context authority and runner are reused without unreviewed API behavior drift.
- [ ] C05 Global .specify files and Feature 001 CI surfaces remain untouched.
- [ ] C06 Schema changes, if chosen, are incremental, reversible and separately approved.

## Security / trust

- [ ] S01 Unknown GitHub origin/host lookalike, insecure scheme, redirect, private DNS/IP, path traversal, unsafe symlink and SHA mismatch fail closed.
- [ ] S02 Missing provider/tree/catalog/incomplete page or budget exhaustion remains visibly incomplete.
- [ ] S03 Remote markdown/frontmatter/content cannot execute scripts or grant NHI, OAuth, tools or approvals.
- [ ] S04 Local managed definition precedence survives conflicting same-name external sources with attributable companion refs.
- [ ] S05 No prompt injection from external text can override hard instructions, secrets or security policy.
- [ ] S06 Source and managed definition changes must be CAS/revision/audit/readback safe.
- [ ] S07 Context is minimum necessary, private handles are permission checked and not implicitly copied to other agents.
- [ ] S08 Disable/rollback of definitions affects no existing NHI/grants and no WordPress site state by surprise.

## Planner and runtime

- [ ] P01 Exact same snapshots yield same deterministic match/team digest.
- [ ] P02 One supervisor, at most 4 specialists, at most 8 steps, no cycles or recursive uncontrolled delegation.
- [ ] P03 Scorer explains term/category relevance, source coverage, errors and disqualifications without claiming semantic equivalence.
- [ ] P04 External content and typed source IDs never become executable instructions in Skill selection.
- [ ] P05 Plan and read-only APIs always return execution_authorized=false.
- [ ] P06 Future handoff revalidates every identity, grant, scope, Site Profile/build and approval, separately per executing specialist.
- [ ] P07 Stale source/definition/Skill/context digest, negative grants, replay, exhausted budget and unknown provider deny with no side effects.

## Delivery & acceptance

- [ ] A01 PHP 7.4 + current tests, denied permissions, fuzzed source files and contract errors.
- [ ] A02 Disposable WordPress and MCP read-ability runtime tests with exact package/build identity.
- [ ] A03 No regression to Feature 006 NHI/grants, existing Skill composition, OAuth or read-only Admin experience.
- [ ] A04 Current-head CI evidence and source/governance review done before merge.
- [ ] A05 Future executing phase reviewed and authorized **separately**, including rollback and uncertainty recovery.
- [ ] A06 Production not auto-enabled; the Spec Kit alone cannot change any installed site.

## Scenario traceability

| Scenario | Requirements | Planned tasks |
|---|---|---|
| Admin definition revision and stale CAS | FR-001/002/011 | T020–T025 |
| Exact public source read; malicious GitHub origin | FR-003..005/015/016 | T010–T016 |
| Task ranking and incomplete coverage | FR-006/008/009 | T030–T034 |
| Bounded acyclic multi-Agent team | FR-007/010 | T032–T037 |
| No grant/subject mutation by discovery | FR-011/012 | T014, T024, T036 |
| Governed owner-bound execution denial | FR-012/013 | T040–T045 |
| PHP and WordPress/MCP compatibility | FR-014/017 | T005, T015, T025, G02–G04 |
