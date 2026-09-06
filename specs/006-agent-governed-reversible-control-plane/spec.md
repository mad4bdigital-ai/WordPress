# Feature Specification — Agent-Governed Reversible Control Plane

Feature ID: 006
Status: Draft implementation contract
Target milestone: pre-Production hardening after v0.3 repository certification
Parent PR: #6

## Problem statement

The current control plane is strong at exact provider certification, runtime integrity, source-code mutation denial, global mutation kill-switches, optimistic state guards and isolated MCP servers. The remaining strategic gap is authority and recovery: an authenticated administrator context can still be too broad for autonomous agents, and a valid-but-wrong mutation needs a standard recovery envelope rather than only stale-state prevention.

The product therefore needs a first-class NHI/Agent layer, exact grants, transport-subject binding, approval tickets for high-impact actions, mutation records with read-after-write verification, drift-safe undo, rate/budget controls, and side-channel detection.

## Goals

1. Distinguish autonomous agents from human WordPress principals.
2. Bind authenticated MCP subjects to explicit enabled NHI records without storing plaintext credentials.
3. Make effective authority an intersection, never a union.
4. Forbid wildcard Production grants.
5. Require exact grants for mutation while preserving existing WordPress capability and provider checks.
6. Introduce short-lived approval tickets bound to exact canonical payload hashes.
7. Introduce a provider-neutral mutation envelope with before/after hashes and bounded rollback payloads.
8. Make undo refuse when the post-mutation state has drifted.
9. Record decision evidence rich enough to reconstruct authorization and mutation history.
10. Add NHI-scoped rate limits and mutation budgets.
11. Detect independent MCP write side-channels as a runtime blocker.
12. Preserve all current v0.3 fail-closed/provider-integrity behavior.

## Non-goals for this feature

- Reimplementing OAuth or MCP transport.
- Storing raw bearer/OAuth secrets.
- Generic arbitrary PHP, shell or source-file editing.
- Generic Production wildcard grants.
- Autonomous password reset, user-role mutation or payment/refund automation.
- Deep integrations for every provider before governance is complete.
- External SIEM/WORM backend implementation in the first code slice; the internal contract must make it possible later.

## Actors

### Human administrator

A WordPress user who configures agents, grants, approvals and policies. Human capability checks remain authoritative for administrative configuration.

### NHI / Agent

A non-human identity representing one autonomous client/agent. It has status, transport subject bindings, exact server/ability grants, optional resource constraints, rate limits and mutation budgets.

### Authenticated transport subject

An identity assertion supplied after upstream authentication. Examples may include OAuth client/subject IDs or a fingerprint of a static credential. MAD4B treats this as input evidence, not as authentication it performs itself.

### Provider

A certified WordPress plugin/runtime such as Elementor, JetEngine, WooCommerce, Rank Math, Polylang, LiteSpeed or Bit Flows.

## Core invariants

- INV-001: No privileged mutation executes without `MAD4B_MCP_MUTATION_ENABLED === true`.
- INV-002: Once mutation is globally enabled, MCP-originated mutation requires a resolved enabled NHI unless an explicitly local non-MCP internal execution path is proven and separately authorized.
- INV-003: NHI resolution never uses a plaintext secret stored by MAD4B.
- INV-004: A transport subject may bind to at most one active NHI for the same subject type/site scope.
- INV-005: A disabled NHI grants nothing.
- INV-006: A grant cannot widen WordPress capability, provider certification, server membership or resource policy.
- INV-007: Production wildcard grants are rejected; first implementation uses exact ability names only.
- INV-008: Every mutation decision records the NHI, subject type/fingerprint, ability, server and allow/deny reason.
- INV-009: High-impact operations require an unexpired, unused approval ticket bound to exact `ability + canonical_input + target + NHI` hash material.
- INV-010: Approval tickets are single-use and replay-resistant.
- INV-011: Provider mutation still fails closed on certification/runtime-integrity drift.
- INV-012: Mutation verification uses read-after-write state, not only callback success.
- INV-013: Undo is permitted only if current state matches the recorded post-mutation state/fingerprint.
- INV-014: Rollback payloads are bounded and secret-redacted; operations that cannot be safely reversed are marked `non_reversible` before execution.
- INV-015: A mutation that fails verification is marked failed and provider-specific safe rollback is attempted only when its rollback contract is certified.
- INV-016: Rate/budget exhaustion denies before side effects.
- INV-017: Network-scoped mutations require explicit network grants plus WordPress network capability.
- INV-018: Other independent write-capable MCP planes are a runtime blocker unless explicitly federated/read-only.

## User stories and acceptance scenarios

### US1 — Provision a least-privilege agent

As an administrator, I can create an agent, bind an authenticated subject fingerprint, and grant exactly selected abilities so that an SEO agent cannot inherit database/plugin/filesystem powers.

Acceptance:
- create disabled agent → no authority;
- enable without grants → no mutation authority;
- exact grant `seo/update-meta` → that ability may proceed to remaining gates;
- unrelated `mad4b/database-update` → denied;
- `*` grant → rejected in Production policy;
- disabling agent immediately denies subsequent calls.

### US2 — Intersect token scopes with NHI grants

As the control plane, I evaluate transport/token scopes as a subset of NHI authority.

Acceptance:
- NHI grants A+B, token scope A → only A effective;
- token scope C not granted to NHI → C denied;
- absent scope context on a transport configured to require scopes → fail closed;
- a read-only scope cannot be widened by admin server membership.

### US3 — Approve a high-impact mutation

As an administrator, I can inspect a plan and issue a short-lived approval for one exact operation.

Acceptance:
- ticket includes NHI, ability, canonical payload hash, target fingerprint, expiry and approver;
- payload changes by one field → ticket invalid;
- different NHI → invalid;
- expired → invalid;
- used ticket → replay denied;
- approval does not bypass stale-state/provider/capability checks.

### US4 — Execute and verify a mutation

As an agent, a mutation executes only after all gates pass, captures before-state where supported, and verifies the resulting state.

Acceptance:
- decision graph is recorded before execution;
- before-state hash/fingerprint is recorded;
- callback success followed by mismatched readback → mutation not `verified`;
- successful readback → after hash recorded;
- result returns mutation ID and undo metadata if reversible.

### US5 — Undo without overwriting newer work

As an administrator/authorized recovery agent, I can undo a reversible mutation only while the current state still equals the recorded after-state.

Acceptance:
- current==after → restore before-state through certified provider contract;
- current!=after → automatic undo denied with `mad4b_undo_state_drift`;
- expired undo → denied;
- unauthorized NHI/user → denied;
- successful undo itself is audited as a new mutation/recovery event.

### US6 — Limit blast radius

As an administrator, I can bound each agent by requests and mutations per window and affected-object budgets.

Acceptance:
- budget decrement is atomic enough to avoid obvious concurrent overrun;
- exhausted budget denies before provider callback;
- read and mutation limits are independently expressible;
- Breakglass does not silently reuse normal agent budget; it has stricter separate policy.

### US7 — Detect competing write planes

As an operator, runtime self-test identifies installed/active MCP systems that expose independent privileged mutation authority.

Acceptance:
- known/declared read-only peer → informational;
- unknown write-capable peer → blocker `mcp_write_side_channel_detected`;
- explicit federation policy may downgrade only with auditable configuration;
- no automatic disabling/uninstalling of other plugins.

## Functional requirements

### Identity and subject binding

- FR-001 Create/read/update/disable NHI records via admin-only internal services and later admin UI/abilities.
- FR-002 NHI has immutable public ID, unique slug, label, status, optional WordPress principal association, timestamps and revision.
- FR-003 Subject bindings store subject type plus SHA-256 fingerprint/identifier, not a bearer secret.
- FR-004 Subject resolution is pluggable through `mad4b_scp_authenticated_subject_context` and may be supplied by MCP adapter integration or another certified authenticator.
- FR-005 Missing/ambiguous subject resolution fails mutation closed.

### Grants

- FR-010 Exact server/ability grants are stored normalized.
- FR-011 Grant evaluation supports deny precedence.
- FR-012 First implementation does not support wildcard ability grants.
- FR-013 Optional resource constraints use a versioned JSON schema and are deny-by-default when a writer declares that resource constraints are required.
- FR-014 Effective permission can be inspected without executing the ability.

### Authorization engine

- FR-020 Core and adapter mutation permission wrappers call one central authorization engine after WordPress capability and global mutation checks, before provider side effects.
- FR-021 Authorization input includes ability name, server ID/surface, readonly/destructive flags, provider, target/resource context and request identity context.
- FR-022 Authorization output is structured: `allowed`, `reason_code`, `agent_public_id`, `subject_fingerprint`, `matched_grant`, `scope_result`, `budget_result`, `approval_required`.
- FR-023 Denials are converted to safe WordPress permission failures while detailed reason is retained in audit without leaking secrets to unauthorized clients.

### Approval

- FR-030 Ability metadata/policy classifies impact: read, low, high, exceptional.
- FR-031 High and exceptional mutations require approval by default.
- FR-032 Approval uses canonical JSON serialization with sorted object keys and normalized scalar representation.
- FR-033 Approval hash covers contract version, site identity, NHI, ability, server, provider, target and canonical input.
- FR-034 Tickets have TTL, single-use state, approver ID and reason.
- FR-035 Breakglass uses a distinct approval class and cannot consume a normal mutation ticket.

### Mutation envelope and undo

- FR-040 Mutation orchestration supports plan, execute, verify and undo phases.
- FR-041 Provider adapters may implement snapshot/readback/restore interfaces without exposing generic arbitrary restore.
- FR-042 Before/after state persisted for rollback is bounded; oversized payloads mark operation non-reversible or use a future external snapshot reference.
- FR-043 Mutation records store hashes even when full state is not retained.
- FR-044 Undo TTL is policy-controlled and never implies unlimited rollback.
- FR-045 Undo re-runs current authorization and capability checks.
- FR-046 Undo requires current state to match recorded after hash/fingerprint.

### Rate/budget

- FR-050 Per-agent request rate limits and mutation budgets are separate.
- FR-051 Limits are configurable per time window and have safe defaults for Production profiles.
- FR-052 Budget checks happen before approval consumption and side effects when possible.

### Side-channel detection

- FR-060 Runtime inventory classifies MCP servers/Ability exporters known to the site.
- FR-061 MAD4B maintains policy classifications `self`, `certified_federated`, `read_only_peer`, `unknown`.
- FR-062 Unknown write-capable peer is a self-test blocker.

### Audit

- FR-070 Every authorization decision has correlation/request ID.
- FR-071 Mutation records reference authorization decision and approval ticket.
- FR-072 Sensitive values are redacted before persistence.
- FR-073 Existing hash-chain semantics remain; schema is prepared for transactional append-only table migration.

## Security requirements

- SEC-001 No token in URL support.
- SEC-002 No plaintext token persistence.
- SEC-003 Constant-time comparison where fixed secret-derived fingerprints are compared and timing could matter.
- SEC-004 SQL uses `$wpdb->prepare()` and normalized tables use keys/indexes for subject/grant lookup.
- SEC-005 JSON constraints must be size bounded.
- SEC-006 Approval and mutation payloads reject excessive size/depth before canonicalization.
- SEC-007 Serialized PHP objects are not accepted as policy/rollback payloads.
- SEC-008 Admin UI/actions require WordPress capability and CSRF nonce.
- SEC-009 Multisite tables/policies clearly choose site-local versus network scope; first implementation is site-local unless network support is explicitly implemented.
- SEC-010 No automatic privilege migration that widens legacy users/agents.

## Observability requirements

Runtime self-test adds:
- NHI storage ready;
- enabled mutation NHI exists when mutation globally enabled;
- no duplicate subject bindings;
- no wildcard Production grants;
- approval storage ready;
- mutation storage ready;
- side-channel blockers;
- audit-chain state;
- provider certification blockers.

## Compatibility

- WordPress 6.9+
- PHP 7.4+
- official MCP Adapter exact certified baseline remains 0.6.1 until re-certified.
- No PHP enums/readonly properties/union types that break PHP 7.4.

## Definition of done

This feature is complete only when static contract tests, PHP 7.4/8.1/8.3 lint, disposable WP 6.9/latest runtime tests and new negative authorization/approval/undo tests all pass on the same exact PR head; package provenance is exact-head; PR remains Draft until target-site staging certification proves subject resolution and policy behavior in the real MCP transport.
