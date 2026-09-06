# Tasks — Agent-Governed Reversible Control Plane

Status legend: `[ ]` pending, `[~]` implemented/in progress but not yet fully certified at the required boundary, `[x]` complete with applicable negative/runtime evidence.

Repository CI success is not target-site certification. Production write remains gated separately.

## Evidence checkpoints

### Core governance + Admin UX runtime checkpoint

Exact head:

```text
c2d7ba3d900097be35b6d2311f603a0c77f2d338
```

All seven repository workflows were `SUCCESS` on that SHA. Runtime Integration passed on WordPress 6.9 and current `latest`, including:

- reversible post mutation and drift-safe undo;
- governance visibility and exact approval planning;
- transactional budget reservation and real two-process contention;
- clean MCP peer inventory plus adversarial write-side-channel blocking;
- append-only audit concurrency and tamper detection;
- read-only Admin Governance Console smoke.

### Spec-consistency checkpoint

`MAD4B Spec Consistency` was added after the checkpoint above and passed against the reconciled Schema v4 normative docs. A final exact-head cycle is required whenever this ledger/contract changes.

### Connection-governance expansion

The connection-readiness work expands the earlier Adapter-registry-only peer proof. Repository certification must now also prove independent MCP-looking REST transports fail governed mutation closed, while the read-only Connection surface distinguishes local readiness, remote endpoint preflight and a real externally verified handshake.

### `mad4b-write` governed-ingress checkpoint

Exact implementation head:

```text
33b20ab0f5c4dbf93769b95830320ea4280b60be
```

All **nine** repository workflows were `SUCCESS` on that SHA. `MAD4B Connection Governance` passed on both WordPress 6.9 and current `latest`, including:

- all five governed MAD4B server routes/permission callbacks;
- `mad4b-write` projection from explicit runtime `annotations.readonly === false` only;
- exact route/server transport binding;
- specialist-server grant cannot authorize the same Ability through `mad4b-write`;
- exact `mad4b-write` grant remains an independent authority record;
- independent `/mosmcp/v1/mcp`-shaped transport remains fail-closed;
- WordPress REST namespace-index callback cannot hide a foreign MCP callback.

The concurrency key for Connection Governance is also bound to the exact head SHA so a stale runner from an older PR head cannot hold newer exact-head certification behind the same PR-wide group.

## Phase 0 — Specification and guardrails

- [x] T000 Constitution: trust, least privilege, provider certification, reversible mutation and side-channel rules.
- [x] T001 Feature spec with invariants, FR/SEC requirements and Definition of Done.
- [x] T002 Research decisions from attached competitor samples only.
- [x] T003 Normalized site-local Schema v4 model for agents/subjects/grants/approvals/mutations/budgets/budget windows/audit events/audit heads.
- [x] T004 Architecture/rollout plan.
- [x] T005 Ability/integration contracts.
- [x] T006 Dedicated `MAD4B Spec Consistency` CI checks required Spec Kit files, constitutional/security markers, current Schema v4 documentation and implementation-referenced invariants; stale v2/option-audit claims fail the contract.

## Phase 1 — Governance schema foundation

### T010 — `MAD4B_SCP_Schema`

- [x] Schema v4 with nine normalized tables.
- [x] Site-local `$wpdb->prefix` table helpers.
- [x] Idempotent activation/boot upgrade.
- [x] No default enabled agent, subject, grant or approval.
- [x] Missing/partial schema blocks governed mutation.
- [x] Fresh runtime activation proven on WordPress 6.9/latest.

### T011 — Bootstrap dependency order

- [x] Governance dependencies load before abilities/adapters/plugin boot.
- [x] PHP 7.4 / 8.1 / 8.3 compatibility certified.
- [x] Audit head/legacy anchor initialize only after schema readiness.

## Phase 2 — Identity and exact grants

### T020 — `MAD4B_SCP_Identity_Context`

- [x] Normalized authenticated subject context.
- [x] `mad4b_scp_authenticated_subject_context` bridge.
- [x] Request ID generation/reuse.
- [x] Raw token/authorization/password/secret material rejected.
- [x] Exact non-wildcard token scopes only.
- [x] Deterministic subject fingerprint.
- [x] Negative identity/scope/secret tests.

### T021 — `MAD4B_SCP_Agent_Registry`

- [x] Create/update/disable agents.
- [x] Optimistic revision guard.
- [x] Bind/disable subject fingerprints.
- [x] Exact grant/revoke with deny precedence.
- [x] Grant creation validates mounted server/ability/provider.
- [x] Wildcard and authority mismatch denial.

### T022 — Effective permission graph

- [x] Central authorization decision object.
- [x] NHI grant ∩ token scope ∩ server ∩ provider ∩ WP capability ∩ resource policy.
- [x] Bounded secret-safe allow/deny audit evidence.
- [x] Effective-access simulation consumes no budget/approval and executes no provider action.

### T023 — Core mutation integration

- [x] WordPress capability remains authoritative.
- [x] Global mutation switch mandatory.
- [x] Bound enabled NHI + exact grant required for governed mutation.
- [x] Fail-closed denial paths runtime/static tested.

### T024 — Adapter mutation integration

- [x] Adapter writers route through central authorization.
- [x] No adapter-local alternative NHI authority.
- [x] Provider certification cannot be widened by grants.

## Phase 3 — Governance visibility

### T030 — `mad4b/runtime-authority-status`

- [x] Schema/mutation/NHI/grant/approval/budget state.
- [x] Wildcard and MCP peer blockers.

### T031 — `mad4b/agent-list`

- [x] Admin-only bounded non-secret NHI listing.
- [x] Runtime-certified.

### T032 — `mad4b/agent-effective-access`

- [x] Admin-only exact-access simulation.
- [x] Provider runtime/impact/approval/constraint/decision visibility.
- [x] No execution/budget/ticket consumption.

## Phase 4 — Approval tickets

### T040 — Canonicalizer

- [x] Recursively sorted object JSON; array order preserved.
- [x] Size/depth/type bounds.
- [x] Exact SHA-256 approval envelope.
- [x] Key-order equivalence and payload-mismatch tests.

### T041 — `MAD4B_SCP_Approval_Tickets`

- [x] Pending create and admin approve/revoke services.
- [x] Bounded TTL.
- [x] Atomic `approved -> used` consume.
- [x] Mutation/breakglass/recovery class separation.
- [x] Exact NHI/server/ability/provider/target/payload binding.
- [x] Replay/mismatch denial.

### T042 — Impact policy

- [x] read/low/high/exceptional classification.
- [x] High-impact hard minimums include admin writers and undo.
- [x] Raw DB Breakglass remains exceptional.

### T043 — `mad4b/approval-plan`

- [x] No provider mutation.
- [x] Exact enabled NHI/grant/server/provider/target validation.
- [x] Pending ticket only; never auto-approves.

## Phase 5 — Mutation envelope and undo

### T050 — `MAD4B_SCP_Mutation_Manager`

- [x] Durable mutation lifecycle records.
- [x] Before/after fingerprints.
- [x] Bounded rollback payload + integrity hash.
- [x] Read-after-write verification.
- [x] Parent recovery lineage.

### T051 — Reversible post-update pilot

- [x] Snapshot selected mutable fields.
- [x] Persist envelope before provider write.
- [x] Execute/restore through `wp_update_post()`.
- [x] Readback verification and after fingerprint.
- [x] Runtime proof on WordPress 6.9/latest.

### T052 — `mad4b/mutation-get`

- [x] Bounded metadata response.
- [x] Rollback payload/hash not exposed.

### T053 — `mad4b/mutation-undo`

- [x] Current authorization rerun.
- [x] Exact high-impact approval required.
- [x] Undo expiry + after-state drift guard.
- [x] Restore original state + readback verify.
- [x] Child recovery record.
- [x] Deliberate newer human edit produces `mad4b_undo_state_drift` without overwrite.

### T054 — Extend reversible adapters deliberately

- [ ] Selected Media metadata.
- [ ] Rank Math allowlisted fields.
- [ ] Polylang language assignment.
- [ ] WooCommerce bounded product fields.
- [ ] JetEngine certified fields.
- [ ] Elementor only where provider/native restore is certified.

Plugin lifecycle, structured DB mutation and Flow execution remain high-impact/non-reversible unless an explicit provider-safe restore contract exists.

## Phase 6 — Blast-radius controls

### T060 — Budget config/store

- [x] requests/mutations/affected_objects/external_actions.
- [x] Bounded windows/counts/costs.
- [x] Transactional DB storage.

### T061 — Runtime windows/counters

- [x] DB row serialization with `FOR UPDATE`.
- [x] Check before side effects.
- [x] Bounded cleanup/window rollover.
- [x] Two-process contention proves no oversubscription.

### T062 — Authorization integration

- [x] Budget evidence in authorization decision.
- [x] Reserve before approval consume.
- [x] Rejected/missing approval rolls reservation back.
- [x] Exhausted budget leaves approval unused.

## Phase 7 — MCP peer side-channel governance

### T070 — Official Adapter peer inventory

- [x] Runtime inventory of actual registered MCP Adapter servers/tools.
- [x] Runtime semantics classification.
- [x] No auto-disable.
- [x] Default Adapter execute-ability path evaluated against reachable public writes.

### T071 — Central side-channel blocker

- [x] Unknown privileged Adapter write path → `mcp_write_side_channel_detected`.
- [x] Explicit Adapter read-only peer remains non-blocking.
- [x] Central blocker occurs before budget/approval consumption.
- [x] Adversarial rogue Adapter writer proven on WordPress 6.9/latest.

### T072 — Independent/foreign MCP transport inventory

- [x] Official Adapter server routes are derived from runtime server objects.
- [x] MCP-looking REST routes outside the Adapter registry are inventoried separately.
- [x] Active MCP/model-context plugin basenames outside the certified Adapter/Control Plane pair are surfaced as foreign evidence.
- [x] Unreviewed independent MCP transport fails governed mutation closed with `mcp_foreign_transport_unreviewed` + `mcp_write_side_channel_detected`.
- [x] No runtime bypass filter can silently suppress the foreign-transport verdict.
- [x] Disposable `/mosmcp/v1/mcp`-shaped independent REST transport blocker runtime-certified on WordPress 6.9/latest at `33b20ab…`.

## Phase 8 — Audit storage hardening

### T080 — Append-only transactional audit

- [x] Schema v4 `audit_events` + `audit_heads`.
- [x] Immutable append events with monotonic sequence/hash linkage.
- [x] Locked head serialization.
- [x] Preinitialized head avoids first-write transaction race.
- [x] Legacy option retained read-only and cryptographically anchored.
- [x] Explicit commit-only joined sink dispatch; rollback drops pending dispatch.
- [x] Bounded chain verifier.
- [x] Two-process concurrency + tamper/restore runtime proof on 6.9/latest.

### T081 — External sink interface

- [x] Post-commit `mad4b_scp_audit_committed` hook.
- [x] Sink exceptions contained/logged without secret leakage.
- [ ] External SIEM/WORM implementation deferred to D011.

## Phase 9 — Admin product UX

The certified first slice is intentionally read-only; it does not create a parallel mutation path.

### T090 — Overview/runtime health
- [x] Admin menu/page requires `manage_options`.
- [x] Authority/blockers/schema/mutation/self-test/audit readiness displayed.
- [x] Runtime UI smoke PASS on WordPress 6.9/latest at `c2d7ba3d…`.

### T091 — Agents/NHI
- [x] Bounded agent status/environment/subject/grant/budget view.
- [ ] Agent mutation controls intentionally deferred.

### T092 — Effective access preview
- [x] Read-only per-agent effective access uses governance service.
- [x] Runtime-certified.

### T093 — Grants/resource constraints
- [x] Effective grant/provider/constraint/decision visibility.
- [ ] Nonce-protected grant-management UX deferred.

### T094 — Pending approvals
- [x] Bounded approval evidence without payload/secret exposure.
- [ ] Approve/revoke UI controls deferred to separate nonce/action review.

### T095 — Mutation/undo history
- [x] Bounded mutation/recovery evidence without rollback payload.
- [ ] Undo button intentionally deferred; governed Ability path already exists.

### T096 — Provider/runtime certification
- [x] Runtime self-test + MCP peer status surfaced.
- [ ] Expanded provider-by-provider visual table optional future UX.

### T097 — Diagnostics/side-channel blockers
- [x] Authority and MCP peer blockers displayed read-only.
- [x] Static contract proves no POST/write primitive.
- [x] Runtime render proves no governance-state mutation and no sensitive audit marker leakage.

### T098 — Connection / transport console
- [x] Dedicated `manage_options` read-only Connection submenu implemented.
- [x] Five endpoints derived from actual MCP Adapter server route metadata rather than hardcoded URL assumptions.
- [x] Both WordPress REST URL forms are handled without confusing rewrite form with route identity.
- [x] REST route registration and exact transport permission callback binding displayed.
- [x] Local transport readiness separated from HTTPS remote endpoint preflight.
- [x] Real external handshake is explicitly unverified locally; no self-certification.
- [x] `mad4b/connection-status` mounted read-only on `mad4b-read`.
- [x] Credential material/creation excluded; only bounded non-sensitive subject facts are displayed.
- [x] No outbound self-probe/SSRF path.
- [x] Foreign MCP route/plugin evidence surfaced.
- [x] Runtime UI/transport proof PASS on WordPress 6.9/latest at `33b20ab…`.

### T099 — `mad4b-write` governed ingress

- [x] Fifth custom MCP server `mad4b-write` registered independently from content/admin/breakglass.
- [x] Tool membership is projected only from already registered content/admin candidates whose runtime Ability metadata has explicit `annotations.readonly === false`.
- [x] Missing/unknown readonly annotation fails closed; representative read-only abilities are absent.
- [x] No generic execute-any dispatcher is introduced.
- [x] Breakglass raw SQL is not projected onto `mad4b-write`.
- [x] Request-local `MAD4B_SCP_Transport_Context` binds exact `/mcp/<server-id>` route and stores no credential/session material.
- [x] Route/server mismatch fails closed and leaves no stale transport authority.
- [x] Central authorization resolves actual MCP transport before exact-grant lookup and exact approval consumption.
- [x] Specialist grant for `mad4b-content + mad4b/content-update-post + core` cannot authorize the same Ability through `mad4b-write`.
- [x] Exact `mad4b-write + mad4b/content-update-post + core` grant remains a distinct authority record.
- [x] Connection Console exposes write readiness/count/gates read-only with no enable/grant/mutation action.
- [x] Runtime proof PASS on WordPress 6.9/latest at `33b20ab…`.
- [x] Connection Governance concurrency is exact-head keyed so a stale older runner cannot hold newer-head certification in the same PR-wide group.

Any future Admin write action must use reviewed capability + WordPress nonce + service-level validation and must not create an alternative authority path.

## Phase 10 — CI and staging certification

### T100 — Static contracts

- [x] `agent-governance-contract.py` covers Schema v4, identity/grants, budgets, approvals, mutation/undo, audit and bootstrap invariants.
- [x] MCP peer governance contract.
- [x] Audit persistence contract.
- [x] Read-only Admin Governance UI contract.
- [x] Spec Kit consistency contract + dedicated workflow.
- [x] `connection-readiness-contract.py` covers five-endpoint truth, transport context, `mad4b-write` projection/isolation, no-secret/no-self-probe rules and foreign MCP fail-closed semantics.
- [x] Dedicated `MAD4B Connection Governance` workflow covers static and WordPress 6.9/latest runtime evidence.
- [x] Main `MAD4B Site Control Plane` workflow requires the fifth governed surface and transport-binding contracts.

### T101 — Runtime integration

- [x] WordPress 6.9 + latest activation/migration.
- [x] No default authority.
- [x] Approval canonicalization/replay resistance.
- [x] Reversible post update + undo + deliberate drift denial.
- [x] Governance visibility/approval planning.
- [x] Budget rollback/exhaustion/rollover/concurrency.
- [x] MCP peer clean inventory + rogue Adapter write blocker.
- [x] Append-only audit concurrency + tamper detection.
- [x] Admin Governance Console read-only/runtime/no-leak smoke.
- [x] Connection readiness + independent foreign MCP REST blocker runtime proof on WordPress 6.9/latest.
- [x] `mad4b-write` exact transport route and specialist-grant isolation runtime proof on WordPress 6.9/latest.
- [x] Namespace-index anti-hijack runtime proof on WordPress 6.9/latest.
- [ ] Add a dedicated runtime ticket-expiry scenario if expiry is not already exercised independently from static/unit coverage.

### T102 — Packaging

- [x] Exact package workflow is part of the final repository certification cycle.
- [ ] Independently verify final artifact SHA256SUMS immediately before target staging deployment.

### T103 — Real target staging

- [ ] Separate WordPress Staging target exists and reports environment `staging`.
- [ ] Exact final package deployed on Staging.
- [ ] Remote HTTPS endpoint preflight has no blocker.
- [ ] Real MCP initialize/tool discovery succeeds on the intended MAD4B server.
- [ ] When `mad4b-write` is used, remote tool discovery exposes only the certified write projection.
- [ ] Exact MCP transport subject bridge evidence.
- [ ] Dedicated staging NHI with minimum exact grants.
- [ ] Exact `mad4b-write` grants used for write-ingress calls; specialist-server grants are not treated as aliases.
- [ ] Cross-server negative proof: specialist grant cannot authorize the same Ability through `mad4b-write` on the real Staging transport.
- [ ] Runtime authority/self-test PASS on deployed target.
- [ ] No unreviewed independent MCP transport/foreign MCP write-side-channel blocker.
- [ ] Benign approved reversible content mutation + readback PASS.
- [ ] Exact approved undo PASS.
- [ ] Stale mutation rejection.
- [ ] Deliberate after-state human drift → undo DENY without overwrite.
- [ ] Budget exhaustion → DENY before ticket consume.
- [ ] Append-only audit valid for success/rejection paths.
- [ ] Mutation gates returned OFF after certification unless controlled staging deliberately continues.

## Production gate

**Production write remains NO-GO. Repository certification is necessary but not sufficient.**

Before Production write:

- [x] T006 dedicated spec-consistency gate implemented.
- [ ] Required reversible adapter scope for intended Production operations resolved; T054 remains open beyond post-update pilot.
- [x] NHI/exact grants/approval/budget/side-channel core contracts repository-certified.
- [x] `mad4b-write` transport/grant isolation and Connection/foreign-MCP expansion repository runtime-certified at the `33b20ab…` implementation checkpoint.
- [x] Append-only audit repository durability/integrity certified.
- [x] Read-only Admin Governance + Connection Consoles repository-certified.
- [ ] Target staging T103 PASS.
- [ ] Exact deployed provider/runtime certification PASS.
- [ ] No target-site MCP write-side-channel blocker.
- [ ] Dedicated Production NHI with exact minimal non-wildcard grants, including exact `mad4b-write` server coordinates where used.
- [ ] Production approval/recovery policy validated at target boundary.
- [ ] Explicit operator authorization to enable mutation in Production.

## Deferred branches

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
- [ ] D011 SIEM/WORM connector implementation using post-commit audit hook.
- [ ] D012 anomaly/ML prompt-injection classifier as defense-in-depth.
- [ ] D013 mobile/SaaS dashboard/billing/marketplace.

Never planned inside normal WordPress MCP:
- arbitrary PHP execution;
- shell execution;
- live source editor;
- unrestricted raw filesystem/source mutation;
- host SSH/system service control.