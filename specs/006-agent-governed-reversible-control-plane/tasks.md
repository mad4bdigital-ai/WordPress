# Tasks — Agent-Governed Reversible Control Plane

Status legend: `[ ]` pending, `[~]` implemented/in progress but not yet fully certified at the required boundary, `[x]` complete with applicable negative/runtime evidence.

The order is dependency-driven. A task is marked complete only when the implementation and its applicable negative tests are present. Repository CI success is not target-site certification.

## Evidence checkpoint

Repository-certified core-governance head before the Admin UX slice:

```text
898f1ae72a4cc648b65617011caccde4928f58c3
```

All seven repository workflows were SUCCESS on that exact SHA. Runtime Integration passed on WordPress 6.9 and current `latest`, including reversible mutation/undo, governance visibility, transactional budget contention, MCP write-side-channel blocking, and append-only audit concurrency/tamper detection.

The read-only Admin Governance Console is implemented after that checkpoint and remains `[~]` until the new exact-head CI cycle is green.

## Phase 0 — Specification and guardrails

- [x] T000 Constitution: trust, least privilege, provider certification, reversible mutation and side-channel rules.
- [x] T001 Feature spec with invariants, FR/SEC requirements and Definition of Done.
- [x] T002 Research decisions from attached competitor samples only.
- [x] T003 Normalized site-local data model for agents/subjects/grants/approvals/mutations/budgets/audit.
- [x] T004 Architecture/rollout plan.
- [x] T005 Ability/integration contracts.
- [ ] T006 Add dedicated spec-consistency CI that checks required Spec Kit files and implementation-referenced security invariants as one explicit contract.

## Phase 1 — Governance schema foundation

### T010 — `MAD4B_SCP_Schema`

- [x] Schema v4 with nine normalized tables: agents, subjects, grants, approvals, mutations, budget config, budget windows, audit events, audit heads.
- [x] Table helpers use current site `$wpdb->prefix`.
- [x] Activation/boot upgrade is idempotent.
- [x] No default agent, subject, grant or approval is created.
- [x] Migration/readiness failure exposes a blocker and mutation fails closed.
- [x] Fresh install and WordPress 6.9/latest runtime activation proven.

### T011 — Bootstrap dependency order

- [x] Schema/identity/registry/policy/audit/approval/budget/authorization load before abilities/adapters/plugin boot.
- [x] PHP 7.4 / 8.1 / 8.3 syntax certified.
- [x] Plugin activation upgrades schema before runtime use.

## Phase 2 — Identity and exact grants

### T020 — `MAD4B_SCP_Identity_Context`

- [x] Normalized validated context object.
- [x] `mad4b_scp_authenticated_subject_context` bridge.
- [x] Request ID generation/reuse.
- [x] Raw token/authorization/password/secret material rejected from identity context.
- [x] Exact non-wildcard scopes only.
- [x] Deterministic subject fingerprint.
- [x] Negative tests for missing/malformed identity, raw secret fields and wildcard scopes.

### T021 — `MAD4B_SCP_Agent_Registry`

- [x] Create/update/disable agents.
- [x] Optimistic `revision` guard.
- [x] Bind/disable subject fingerprints.
- [x] Exact grant/revoke with deny precedence.
- [x] No hard-delete-first authority model.
- [x] Grant creation validates mounted server/ability and provider binding.
- [x] Wildcard and mismatched authority denied.

### T022 — Effective permission graph

- [x] Central `MAD4B_SCP_Authorization` decision object.
- [x] NHI grant ∩ token scope ∩ server ∩ provider ∩ WP capability ∩ resource policy.
- [x] Audit allow/deny decisions with bounded safe detail.
- [x] `agent-effective-access` simulation consumes no budget/ticket and executes no provider action.

### T023 — Integrate core mutation wrapper

- [x] Existing WP capability remains first-line application authority.
- [x] Global mutation switch remains mandatory.
- [x] Enabled bound NHI + exact grant required for governed mutation.
- [x] Precise denial is audited while WordPress external permission behavior remains fail-closed.
- [x] Runtime global-OFF/no-NHI/exact-grant negative paths covered.

### T024 — Integrate adapter base

- [x] Adapter writers route centrally with provider/ability/server/input context.
- [x] No adapter-local alternative NHI authority model.
- [x] Provider certification cannot be widened by grants.

## Phase 3 — Governance visibility

### T030 — `mad4b/runtime-authority-status`

- [x] Schema readiness and version.
- [x] Mutation configuration/effective status.
- [x] Enabled agents/subjects/exact grants/wildcard blockers.
- [x] Approval and budget service readiness.
- [x] MCP peer inventory/write-side-channel blockers.

### T031 — `mad4b/agent-list`

- [x] Admin-only bounded read.
- [x] Non-secret NHI summaries.
- [x] Runtime-certified.

### T032 — `mad4b/agent-effective-access`

- [x] Admin-only simulation.
- [x] Provider runtime, impact, approval requirement, constraints and deny precedence visible.
- [x] No execution/budget/approval consumption.
- [x] Runtime-certified.

## Phase 4 — Approval tickets

### T040 — Canonicalizer

- [x] Stable recursively sorted object JSON while preserving array order.
- [x] Size/depth bounds and unsupported-type rejection.
- [x] SHA-256 exact-operation envelope.
- [x] Key-order equivalence and payload mismatch tests.

### T041 — `MAD4B_SCP_Approval_Tickets`

- [x] Pending create.
- [x] Admin approve/revoke service.
- [x] Bounded TTL.
- [x] Atomic `approved → used` consume.
- [x] `mutation` / `breakglass` / `recovery` class separation.
- [x] Exact NHI/server/ability/provider/target/payload binding.
- [x] Replay and mismatched payload denial proven.

### T042 — Impact policy registry

- [x] Read/low/high/exceptional policy.
- [x] High-impact hard minimums include administrative writers and `mad4b/mutation-undo`; raw DB recovery remains exceptional.
- [x] Non-core provider mutations default conservatively.

### T043 — `mad4b/approval-plan`

- [x] Executes no provider mutation.
- [x] Requires enabled agent, exact grant, mounted server/provider authority and exact target resolution.
- [x] Creates pending ticket only.
- [x] Cannot auto-approve or execute.

## Phase 5 — Mutation envelope and undo

### T050 — `MAD4B_SCP_Mutation_Manager`

- [x] Durable mutation lifecycle records.
- [x] Target/before/after fingerprints.
- [x] Bounded rollback payload with integrity hash.
- [x] Read-after-write verification.
- [x] Parent recovery lineage.

### T051 — Reversible contract: content post update pilot

- [x] Snapshot selected mutable fields.
- [x] Record mutation envelope before provider write.
- [x] Execute through `wp_update_post()`.
- [x] Readback/after fingerprint.
- [x] Restore through `wp_update_post()`, not raw DB.
- [x] Runtime proof on WordPress 6.9/latest.

### T052 — `mad4b/mutation-get`

- [x] Bounded metadata response.
- [x] Rollback payload/hash not exposed.

### T053 — `mad4b/mutation-undo`

- [x] Current authorization rerun.
- [x] High-impact exact approval required.
- [x] Undo expiry check.
- [x] Recorded after-state must match current state.
- [x] Restore and verify original before-state.
- [x] Child recovery mutation record with undo approval reference.
- [x] Deliberate newer human edit produces `mad4b_undo_state_drift` and is preserved.

### T054 — Extend reversible adapters deliberately

- [ ] Selected Media metadata.
- [ ] Rank Math allowlisted fields.
- [ ] Polylang language assignment.
- [ ] WooCommerce bounded product fields.
- [ ] JetEngine certified fields.
- [ ] Elementor only where a provider/native restore contract is certified.

Plugin lifecycle, DB update and Flow execution remain high-impact and non-reversible unless an explicit provider-safe restore contract is implemented.

## Phase 6 — Blast-radius controls

### T060 — Budget config/store

- [x] `requests`, `mutations`, `affected_objects`, `external_actions` dimensions.
- [x] Bounded windows/counts/costs.
- [x] Transactional DB storage; no option/cache counters.

### T061 — Runtime counters

- [x] `SELECT ... FOR UPDATE` serialization.
- [x] Check before provider side effects.
- [x] Window rollover metadata.
- [x] Real two-process contention proves no oversubscription.

### T062 — Authorization integration

- [x] Budget evidence included in authorization decision.
- [x] Budget reserves before approval consumption.
- [x] Missing/rejected approval rolls budget reservation back.
- [x] Exhausted budget denies before approval consumption and leaves ticket approved/unused.

## Phase 7 — MCP peer side-channel governance

### T070 — Peer inventory

- [x] Inventory actual registered MCP servers/tools through the MCP Adapter runtime registry.
- [x] Runtime semantics classification rather than plugin-name heuristics.
- [x] No auto-disable of peers.
- [x] Default Adapter `execute-ability` evaluated against reachable public write abilities.

### T071 — Self-test blocker

- [x] Reachable unknown/privileged write path → `mcp_write_side_channel_detected`.
- [x] Explicit read-only peer path remains non-blocking.
- [x] Central mutation authorization blocks before budget/approval consumption.
- [x] Adversarial rogue-public-write fixture passes on WordPress 6.9/latest.

## Phase 8 — Audit storage hardening

### T080 — Append-only transactional audit

- [x] Schema v4 `audit_events` + `audit_heads` transactional storage.
- [x] Immutable append events with monotonic sequence and hash linkage.
- [x] Existing head serialized by `SELECT ... FOR UPDATE`.
- [x] Head initialized safely before operational transactions.
- [x] Legacy bounded option retained read-only and cryptographically anchored.
- [x] Legacy anchor drift blocks integrity.
- [x] Joined budget/approval audit dispatch occurs only after explicit commit; rollback drops pending sink dispatch.
- [x] Bounded chain verification.
- [x] Real two-process concurrent append proves no lost update/deadlock on WordPress 6.9/latest.
- [x] Out-of-band tamper makes `verify_chain()` fail and exact restoration makes it pass again.

### T081 — External sink interface

- [x] Post-commit `mad4b_scp_audit_committed` integration hook.
- [x] Sink exceptions are contained/logged without exposing secret summary material.
- [ ] External SIEM/WORM implementation itself is deferred to D011.

## Phase 9 — Admin product UX

First UX slice is intentionally read-only. No grant/approve/revoke/undo POST action is exposed yet.

### T090 — Overview/runtime health

- [~] Admin menu/page implemented with `manage_options`.
- [~] Authority state, blockers, schema, mutation state, self-test and audit readiness displayed.
- [ ] Final exact-head runtime UI certification pending.

### T091 — Agents/NHI

- [~] Bounded agent table with status/environment/subject/grant/budget counts.
- [ ] Agent mutation controls intentionally deferred.

### T092 — Effective access preview

- [~] Read-only per-agent effective-access preview implemented using the governance service.
- [ ] Final exact-head runtime UI certification pending.

### T093 — Grants/resource constraints

- [~] Effective grant/provider/constraint/decision visibility implemented.
- [ ] Dedicated nonce-protected grant management UX deferred.

### T094 — Pending approvals

- [~] Bounded approval evidence view implemented without payload hash exposure.
- [ ] Approve/revoke controls deferred until separate nonce/action review.

### T095 — Mutation/undo history

- [~] Bounded mutation/recovery evidence view implemented without rollback payload exposure.
- [ ] Undo action button intentionally deferred; runtime Ability path is already governed/certified.

### T096 — Provider/runtime certification

- [~] Runtime self-test and MCP peer status surfaced in Overview.
- [ ] Dedicated provider-by-provider visual table can be expanded later.

### T097 — Diagnostics/side-channel blockers

- [~] Authority and MCP peer blockers displayed read-only.
- [ ] Final exact-head runtime UI certification pending.

Admin write actions, when introduced, must use `manage_options` or a narrower reviewed capability, WordPress nonce, exact service-level validation, and must not create an alternative authority path.

## Phase 10 — CI and staging certification

### T100 — `agent-governance-contract.py`

- [x] Static negative assertions cover Schema v4, no-default-authority, wildcard denial, secret URL/context bans, budgets, approvals, reversible mutation, append-only audit and bootstrap ordering.
- [x] Separate MCP peer and audit persistence contracts exist.
- [~] Admin Governance UI static contract added; final exact-head CI pending.

### T101 — Runtime integration expansion

- [x] WordPress 6.9 + latest activation/migration.
- [x] No default authority.
- [x] Exact approval canonicalization/replay resistance.
- [x] Reversible post update + undo + deliberate drift denial.
- [x] Governance visibility/approval planning.
- [x] Budget rollback/exhaustion/rollover/concurrency.
- [x] MCP peer clean inventory + rogue write blocker.
- [x] Append-only audit concurrency + tamper detection.
- [~] Admin Governance UI runtime smoke added; final exact-head run pending.
- [ ] Add explicit runtime expiry test if not already covered by a dedicated runtime scenario.

### T102 — Packaging

- [~] Package workflow was green at repository-certified checkpoint `898f1ae7…`.
- [ ] Re-certify package on final Admin UX/documentation head.
- [ ] Independently verify final artifact SHA256SUMS before target staging deployment.

### T103 — Real target staging

- [ ] Exact MCP transport subject bridge evidence.
- [ ] Create dedicated staging NHI with minimum exact grants.
- [ ] Runtime authority/self-test PASS on deployed target.
- [ ] Benign approved reversible content mutation + readback PASS.
- [ ] Exact approved undo PASS.
- [ ] Stale mutation rejection.
- [ ] Deliberate after-state human drift → undo DENY without overwrite.
- [ ] Budget exhaustion → DENY before ticket consume.
- [ ] Peer MCP inventory has no write-side-channel blocker.
- [ ] Append-only audit chain valid for success and rejection paths.
- [ ] All mutation gates returned OFF after certification unless explicitly continuing controlled staging.

## Production gate

**Production write remains NO-GO.** Repository runtime certification is necessary but not sufficient.

Before Production write:

- [ ] T006 spec-consistency gate resolved or explicitly dispositioned.
- [ ] Required reversible adapter scope for the intended Production operations resolved; T054 remains open beyond post-update pilot.
- [x] NHI/exact grants/approval/budget/side-channel core repository contracts green at `898f1ae7…`.
- [x] Append-only audit durability adequate for repository decision.
- [ ] Final Admin UX head exact CI green.
- [ ] Target staging T103 PASS.
- [ ] Exact deployed provider/runtime certification PASS.
- [ ] No target-site MCP write-side-channel blocker.
- [ ] Dedicated Production NHI created with exact minimal non-wildcard grants.
- [ ] Production approval/recovery policy validated on the target boundary.
- [ ] Explicit operator authorization to enable mutation in Production.

## Deferred branches after Production core

- [ ] D001 Yoast governed writer.
- [ ] D002 SEOPress governed writer.
- [ ] D003 deep JetSmartFilters mutation APIs.
- [ ] D004 exhaustive commercial JetEngine field schemas.
- [ ] D005 advanced Elementor visual CRUD beyond certified native contracts.
- [ ] D006 advanced WooCommerce order/refund/payment mutations.
- [ ] D007 user password/session/role actions — exceptional/high-risk only.
- [ ] D008 policy DSL/visual builder.
- [ ] D009 multi-approver/Slack/email approvals.
- [ ] D010 network/fleet identity federation.
- [ ] D011 SIEM/WORM connector implementation using the post-commit audit hook.
- [ ] D012 anomaly/ML prompt-injection classifier as defense-in-depth.
- [ ] D013 mobile/SaaS dashboard/billing/marketplace.

Never planned inside normal WordPress MCP:

- arbitrary PHP execution;
- shell execution;
- live source editor;
- unrestricted raw filesystem/source mutation;
- host SSH/system service control.
