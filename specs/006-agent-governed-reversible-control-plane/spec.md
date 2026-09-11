# Feature Specification — Agent-Governed Reversible Control Plane

Feature ID: 006
Status: Draft implementation contract
Target milestone: pre-Production hardening after v0.3 repository certification
Parent PR: #6

## Problem statement

MAD4B Site Control Plane already has exact provider certification, runtime integrity, source-code mutation denial, global mutation kill-switches, optimistic state guards and isolated MCP servers. Feature 006 adds the authority, blast-radius, evidence and recovery layer required for governed autonomous operation: explicit NHI identities, exact grants, authenticated transport-subject binding, exact short-lived approvals, transactional budgets, mutation envelopes, drift-safe undo, MCP write-side-channel blocking and append-only transactional audit evidence.

Repository certification does not authorize Production. Target-site staging remains a separate admission boundary.

## Goals

1. Distinguish autonomous agents from human WordPress principals.
2. Bind authenticated MCP subjects to explicit enabled NHI records without storing plaintext credentials.
3. Make effective authority an intersection, never a union.
4. Forbid wildcard Production grants and wildcard token scopes.
5. Require exact server/ability/provider grants for governed mutation while preserving WordPress capability and provider checks.
6. Bind an MCP mutation to the **actual governed transport server** before exact-grant and approval evaluation, so the same Ability mounted on two servers represents two different authority coordinates.
7. Provide `mad4b-write` as a unified write-only ingress without introducing a generic execute-any primitive.
8. Require short-lived, single-use approval tickets for high/exceptional impact operations.
9. Limit blast radius with transactional NHI budgets.
10. Persist mutation records with before/after fingerprints and read-after-write verification.
11. Make undo refuse when the post-mutation state has drifted.
12. Detect independent MCP write side-channels before budget reservation or approval consumption.
13. Persist security evidence in append-only transactional hash-linked audit storage.
14. Preserve all existing fail-closed/provider-integrity behavior.

## Non-goals

- Reimplement OAuth or MCP transport.
- Store raw bearer/OAuth secrets.
- Generic arbitrary PHP, shell or source-file editing.
- Generic execute-any / ability-name dispatcher through `mad4b-write`.
- Generic Production wildcard grants.
- Autonomous password reset, user-role mutation or payment/refund automation.
- Deep mutation integrations for every provider before an exact restore contract exists.
- Host SSH/system service control from the WordPress plugin.
- External SIEM/WORM backend implementation in the core slice; only the post-commit integration hook is required here.

## Actors

### Human administrator

A WordPress user who configures agents, grants, approvals and policies. Human capability checks remain authoritative for administrative configuration.

### NHI / Agent

A non-human identity representing one autonomous client/agent. It has status, transport-subject bindings, exact grants, optional resource constraints and transactional budgets.

### Authenticated transport subject

Identity evidence supplied after upstream authentication, normalized through `mad4b_scp_authenticated_subject_context`. MAD4B validates this evidence but does not replace upstream authentication.

### Governed MCP transport server

The exact MAD4B MCP server endpoint that accepted the request. Request-local transport identity is non-secret routing evidence and is distinct from user/NHI identity. Once bound, it is authoritative for exact server/ability/provider grant and approval coordinates.

### Provider

A certified WordPress/core or plugin runtime such as Elementor, JetEngine, WooCommerce, Rank Math, Polylang, LiteSpeed or Bit Flows.

## Core invariants

- INV-001: No privileged mutation executes without `MAD4B_MCP_MUTATION_ENABLED === true`.
- INV-002: Once mutation is globally enabled, governed MCP/external mutation requires a resolved enabled NHI.
- INV-003: NHI resolution never uses a plaintext secret stored by MAD4B.
- INV-004: A transport subject binds to at most one active NHI for the same subject type/site scope.
- INV-005: A disabled NHI grants nothing.
- INV-006: A grant cannot widen WordPress capability, provider certification, server membership or resource policy.
- INV-007: Production wildcard grants/scopes are rejected; v1 uses exact ability names.
- INV-008: Mutation decisions record request/NHI/subject/server/ability/provider and allow/deny reason with secret-safe summaries.
- INV-009: High/exceptional operations require an unexpired unused approval ticket bound to exact NHI + server + ability + provider + target + canonical input.
- INV-010: Approval tickets are single-use and replay-resistant.
- INV-011: Provider mutation fails closed on provider certification/runtime-integrity drift.
- INV-012: Mutation verification uses read-after-write state, not callback success alone.
- INV-013: Undo is permitted only when current state matches the recorded post-mutation state/fingerprint.
- INV-014: Rollback payloads are bounded and integrity-protected; unsafe restore contracts are not treated as reversible.
- INV-015: Verification failure is recorded as failure; no success is reported from callback success alone.
- INV-016: Budget exhaustion denies before provider side effects and before approval consumption.
- INV-017: Network-scoped mutations require explicit network authority plus WordPress network capability.
- INV-018: Independent reachable MCP write authority is a runtime blocker unless explicitly classified as safe/read-only/federated.
- INV-019: Audit events are append-only, hash-linked and transactionally serialized; external sink dispatch occurs only after explicit commit.
- INV-020: Admin inspection UX must not create a parallel mutation path.
- INV-021: When an MCP transport is bound, central authorization MUST use that actual server ID before exact-grant lookup and exact approval-ticket consumption; an Ability's registration category/declaration cannot override the active transport.
- INV-022: A route/server mismatch MUST fail closed and MUST clear or leave no stale transport authority.
- INV-023: `mad4b-write` mounts only already-registered Abilities with explicit runtime `annotations.readonly === false`; missing/unknown annotation is not writable.
- INV-024: `mad4b-write` exposes no Breakglass raw SQL and no generic dispatcher. A specialist-server grant does not authorize the same Ability through `mad4b-write`.

## Acceptance scenarios

### US1 — Least-privilege NHI

- create disabled agent → no authority;
- enable without grants → no mutation authority;
- exact grant permits only that mounted ability/provider path;
- unrelated ability denied;
- wildcard grant rejected;
- disabling agent immediately denies subsequent calls.

### US2 — Token scope intersection

- NHI grants A+B, token scope A → only A effective;
- token scope C without grant → denied;
- required scope context absent → denied;
- scope cannot widen server/provider/resource policy.

### US3 — Exact approval

- ticket includes NHI/server/ability/provider/target/class/payload hash/expiry/approver;
- one payload field change invalidates ticket;
- different NHI invalidates ticket;
- expired/revoked/used ticket is denied;
- approval never bypasses stale-state/provider/capability/budget checks.

### US4 — Execute and verify mutation

- persist mutation envelope before provider write;
- record before fingerprint where reversible;
- execute through certified/public provider contract;
- read after write;
- mismatched readback is not verified;
- verified result records after fingerprint and mutation ID.

### US5 — Drift-safe undo

- current==recorded after-state → restore through certified restore contract;
- current!=after-state → `mad4b_undo_state_drift`;
- expired or unauthorized undo → denied;
- successful undo creates child recovery evidence; original history remains immutable.

### US6 — Blast-radius budgets

- budgets cover requests, mutations, affected objects and external actions;
- two concurrent workers cannot oversubscribe a configured bucket;
- exhausted budget denies before ticket use/provider side effect;
- rejected approval rolls the reservation back.

### US7 — MCP peer governance

- known read-only peer → non-blocking evidence;
- reachable unknown privileged writer → `mcp_write_side_channel_detected`;
- blocker is evaluated before budget/approval consumption;
- no automatic uninstall/disable of peer plugins.

### US8 — Append-only audit

- events append with monotonic sequence and previous/entry hash;
- concurrent append does not lose updates or deadlock;
- tamper makes chain verification fail;
- legacy bounded option history is read-only and anchored;
- post-commit sink hook never publishes rolled-back joined events.

### US9 — Read-only Admin Governance Console

- requires `manage_options`;
- displays bounded authority/NHI/access/approval/mutation/audit/provider/peer evidence;
- does not expose rollback payloads or raw subject secrets;
- opening/rendering the page does not alter governance state;
- no grant/approve/revoke/undo POST action exists in the first slice.

### US10 — `mad4b-write` exact transport isolation

- five governed MAD4B servers register, including `/mcp/mad4b-write`;
- `mad4b-write` discovers only Abilities explicitly annotated `readonly=false`;
- representative read-only Abilities do not appear there;
- `/mcp/mad4b-write` binds request-local transport ID `mad4b-write`;
- passing a `/mcp/mad4b-content` request to the write transport permission callback is denied as route mismatch;
- a grant for `mad4b-content + mad4b/content-update-post + core` cannot authorize that Ability through `mad4b-write`;
- a separate exact `mad4b-write + mad4b/content-update-post + core` grant is a distinct authority record;
- foreign-MCP and namespace-index-hijack detection remains effective after the fifth server is added.

## Functional requirements

### Identity and grants

- FR-001 Admin-only services create/update/disable NHI records.
- FR-002 NHI has immutable public ID, unique slug, status, environment and optimistic revision.
- FR-003 Subject bindings persist only normalized fingerprint/non-secret identity evidence.
- FR-004 Subject resolution is pluggable through `mad4b_scp_authenticated_subject_context`.
- FR-005 Missing/malformed/ambiguous subject resolution fails mutation closed.
- FR-010 Exact server/ability/provider grants are normalized.
- FR-011 Deny precedence is supported.
- FR-012 Wildcard ability grants are rejected.
- FR-013 Resource constraints are bounded versioned JSON and deny when unresolved.
- FR-014 Effective access can be simulated without provider execution or budget/ticket consumption.

### Authorization

- FR-020 Core and adapter writers call central `MAD4B_SCP_Authorization` before provider side effects.
- FR-021 Authorization includes ability, effective server, provider, identity, exact grant, scope, constraints, impact, budget and approval state.
- FR-022 Denials use safe external errors while detailed secret-safe reason codes remain in audit evidence.
- FR-023 MCP peer write-side-channel guard runs before budget reservation.
- FR-024 Central authorization resolves the effective server through `MAD4B_SCP_Transport_Context` before exact-grant lookup. If no MCP transport is bound, an explicitly declared internal server remains the compatibility fallback.
- FR-025 Once an MCP transport is bound, the Ability MUST be mounted on that server or authorization fails closed.
- FR-026 Exact approval-ticket consumption binds to the effective transport server, not merely to the Ability's original registration surface.

### Approval

- FR-030 Impact classes are read/low/high/exceptional.
- FR-031 High and exceptional operations require approval by default.
- FR-032 Canonical approval JSON recursively sorts object keys while preserving array order.
- FR-033 Approval hash covers contract/site/NHI/server/ability/provider/target/canonical input.
- FR-034 Ticket TTL and canonical input size/depth are bounded.
- FR-035 Ticket use is atomic `approved -> used`; replay is denied.
- FR-036 Breakglass/recovery/mutation classes are separate.

### Mutation and undo

- FR-040 Mutation lifecycle supports planned/executing/verified/failed/undone evidence.
- FR-041 Reversible paths use provider-specific snapshot/readback/restore contracts rather than generic restore.
- FR-042 Rollback payload is bounded and integrity-hashed.
- FR-043 Before/after hashes remain available even when full state is not exposed.
- FR-044 Undo TTL is bounded and policy-controlled.
- FR-045 Undo reruns current authorization/capability checks.
- FR-046 Undo requires current state to equal recorded after fingerprint.
- FR-047 `mad4b/mutation-get` never returns rollback payload through normal inspection.

### Rate and budgets

- FR-050 Budget dimensions: `requests`, `mutations`, `affected_objects`, `external_actions`.
- FR-051 Budget config and runtime windows are persisted in transactional tables.
- FR-052 Reservation occurs before approval consumption; denied approval rolls reservation back.
- FR-053 Counter updates serialize with DB transactions/row locks and are bounded/cleaned.

### Side-channel and transport governance

- FR-060 Runtime inventory classifies actual registered MCP servers/tools using adapter runtime evidence.
- FR-061 Unknown reachable write path is a blocker.
- FR-062 Read-only peer evidence may remain non-blocking.
- FR-063 `mad4b-write` is constructed from the union of existing content/admin candidates filtered by actual Ability metadata `readonly === false`; no request-selected generic dispatch is permitted.
- FR-064 Transport permission wrappers bind exact `/mcp/<server-id>` route to the governed server before evaluating the existing WordPress policy.
- FR-065 Request-local transport context stores no credential/session material.

### Audit

- FR-070 Every important authorization/mutation event carries correlation/request ID.
- FR-071 Mutation records reference approval and recovery lineage as applicable.
- FR-072 Sensitive values are redacted before persistence/rendering.
- FR-073 Audit evidence is persisted in Schema v4 append-only transactional `mad4b_scp_audit_events` plus locked `mad4b_scp_audit_heads`, with bounded chain verification and anchored legacy option history.
- FR-074 `mad4b_scp_audit_committed` is the post-commit external sink integration point; sink failure must not leak secrets.

### Admin UX

- FR-080 Read-only governance console requires `manage_options`.
- FR-081 Read-only navigation may use GET; state-changing admin controls require WordPress nonce and reviewed capability.
- FR-082 First slice exposes no alternative grant/approve/revoke/undo execution path.
- FR-083 Connection console reports all governed server endpoints and a separate non-mutating `mad4b-write` readiness summary; it MUST NOT contain an enable/write/grant action.

## Security requirements

- SEC-001 No token-in-URL support.
- SEC-002 No plaintext token persistence.
- SEC-003 Constant-time comparison for fixed secret-derived/hash material where applicable.
- SEC-004 SQL uses prepared/bounded queries and normalized indexed tables.
- SEC-005 Resource constraint JSON is size/depth bounded.
- SEC-006 Approval/rollback payloads reject excessive size/depth or unsupported values.
- SEC-007 Serialized PHP objects are not accepted as policy/rollback payloads.
- SEC-008 Admin state-changing UI/actions require reviewed WordPress capability and CSRF nonce; the certified first UI slice is read-only.
- SEC-009 Site-local vs network authority is explicit; first implementation remains site-local unless network support is separately certified.
- SEC-010 Migration never creates enabled authority or widens legacy privilege.
- SEC-011 Normal WordPress MCP does not expose arbitrary PHP/shell/source-code mutation.
- SEC-012 Provider package/version/runtime-integrity drift disables package-backed mutation.
- SEC-013 A specialist-server exact grant or approval MUST NOT be treated as equivalent to the same Ability/provider on `mad4b-write`.
- SEC-014 `mad4b-write` MUST NOT include `mad4b/database-raw-query` or another Breakglass primitive.

## Observability requirements

Runtime authority/self-test reports at least:
- schema readiness/version;
- mutation global/effective status;
- enabled agents/subjects/exact grants/wildcard blockers;
- approval and budget service readiness;
- request-local transport context without credential material;
- `mad4b-write` registration/tool-count/exact-transport-grant readiness;
- MCP peer inventory/write-side-channel blockers;
- append-only audit storage/integrity state;
- provider certification/runtime blockers.

## Compatibility

- WordPress 6.9+.
- PHP 7.4+; no enums/readonly properties/union types that break PHP 7.4.
- Official MCP Adapter exact certified baseline remains 0.6.1 until re-certified.

## Definition of done

Repository scope is complete only when, on the same exact PR head:
- PHP 7.4/8.1/8.3 lint passes;
- adversarial/static governance contracts pass;
- Spec Kit consistency contract passes;
- packaged-provider/security and package provenance checks pass;
- all five governed MAD4B MCP servers, including `mad4b-write`, register with exact route/permission evidence;
- disposable WordPress 6.9 + current latest runtime proves `mad4b-write` write-only projection and specialist-grant/actual-transport isolation;
- disposable WordPress 6.9 + current latest runtime passes identity/grants, approval, budgets/concurrency, reversible mutation/undo/drift denial, MCP side-channel blocking, append-only audit concurrency/tamper and read-only Admin Governance/Connection Console smoke.

Production remains separate: the PR stays Draft and Production mutation stays NO-GO until real target staging proves transport subject resolution, exact deployed provider/runtime state, minimal NHI grants including exact `mad4b-write` coordinates when that ingress is used, governed mutation/readback/undo, blocker-free MCP peer state, valid audit evidence and post-certification mutation gates returned OFF unless deliberately continuing controlled staging.
