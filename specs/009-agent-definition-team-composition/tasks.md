# Tasks — Feature 009 Agent Definition Explorer & Team Composer

Legend: [ ] not started; [~] implemented but unverified; [x] complete with required evidence. **All implementation tasks below are intentionally unstarted**; specification-only delivery is not implementation.

## Phase 0 — Baseline and contracts (blocks code)

- [ ] T000 Pin baseline master and verify PR #528 ancestry; preserve global .specify ownership.
- [ ] T001 Map current Agent Registry NHI roles, Skill Registry IDs, source reader, existing runtime permission APIs; produce precise owner/consumer inventory.
- [ ] T002 Freeze Definition ≠ NHI ≠ Execution Authority lifecycle with CAS and no-grant side-effect contract.
- [ ] T003 Define source-parser dialect fixture sets and explicit AGENTS.md-as-context treatment.
- [ ] T004 Threat model malicious frontmatter, symlinks, origin spoof, stale Git blobs, prompt injection, timeout and partial scanning.
- [ ] T005 Approve typed Abilities schemas and error envelope, versioning and pagination limits; no new ability registration yet.
- [ ] T006 Establish specs-to-test traceability and add doc-only quality checks in a dedicated future CI, without modifying Feature 001 configs.

## Phase 1 — External Agent Explorer (read only)

- [ ] T010 Extract a reusable bounded GitHub source reader, characterizing #528 results before refactor; no change in Skill source semantics.
- [ ] T011 Implement explicit provider/format registry and deny unknown remote hosts/ref syntax/path escapes.
- [ ] T012 Discover supported Agent paths via exact Git tree, preserve truncated/incomplete status and return a stable cursor.
- [ ] T013 Read exact SHA-verified candidate and sanitized preview; never evaluate agent instructions or scripts.
- [ ] T014 Implement source-list/candidates/get read Abilities under existing read permissions.
- [ ] T015 Test host lookalikes/private-IP/redirect/SSRF/tree-overflow/UTF-8/oversize/timeouts/Git SHA mismatch; ensure no mutation.
- [ ] T016 Test actual GitHub egress separately from hermetic fixtures (when explicitly permitted); record uncertainty.

## Phase 2 — Managed Agent Definitions & Studio

- [ ] T020 Select owner-scoped durable representation and prove atomic save/rollback/audit, safe paths and revision CAS.
- [ ] T021 Add validated draft/review/enable/disable transitions, no implicit NHI/grant behavior.
- [ ] T022 Link read-only managed Skill refs and context refs by digest/revision; refuse stale/unknown refs.
- [ ] T023 Create safe admin-only Agent Studio edit/preview/read status UI with nonce/HTML escape and history.
- [ ] T024 Add separate read-only identity/effective-access view; no automatic binding/credential creation.
- [ ] T025 Test concurrent edit, crash+restore, stale revision, privilege denial, content injection and plugin upgrade persistence.

## Phase 3 — Matching, Team Plans & Context

- [ ] T030 Implement bounded deterministic Agent match and explain score, eligibility and source completeness.
- [ ] T031 Integrate Skill Composition as advice; selected Skills pin their managed/external provenance.
- [ ] T032 Implement 1+4 team limit, 8-step DAG and duplicate/cycle/no-supervisor rejection.
- [ ] T033 Generate immutable nonauthorizing Team Plan and canonical digest with separate plan identity.
- [ ] T034 Add conflict/omission report, local precedence and incomplete/budget-exhaustion denial from execution-ready status.
- [ ] T035 Build bounded context selectors with Existing Context Registry handles; no automatic full-chat or secrets collection.
- [ ] T036 Add read-only agent-match-task/agent-team-plan interfaces; prove zero tool execution/grant mutation.
- [ ] T037 Test exact-snapshot determinism, role collision, missing Skills/context, ranking ties, pagination and concurrent requests.

## Phase 4 — Governed Handoff (separate future authorization)

- [ ] T040 Produce implementation-specific authority and owner approval proposal; require a separate authorized PR.
- [ ] T041 Map each team step to an independently authenticated existing NHI and exact server/provider/ability permission; deny missing binding.
- [ ] T042 Revalidate Site Profile, source digests, Skills, context, model/tool availability, budget and approval just before execution.
- [ ] T043 Reuse existing execution idempotency/leases/outbox and bounded receipt/audit for actually approved operations.
- [ ] T044 Test denied grant, expired/replayed approval, schema not ready, stale snapshot, restart, unknown result and parallel workers.
- [ ] T045 Add Staging live acceptance and rollback with installed plugin build identity; Production remains separately gated.

## Final release gates

- [ ] G01 Specification and threat-model owner review.
- [ ] G02 Read-only security and negative fixtures pass PHP 7.4 + current.
- [ ] G03 Verified WordPress + MCP Staging read acceptance with matching source SHA.
- [ ] G04 Existing Skill/NHI/grant catalog and permissions unchanged.
- [ ] G05 Explicit human approval and deployment authorization for any executing phase.
- [ ] G06 Current-head CI, independent policy controls, provenance and rollback evidence.
