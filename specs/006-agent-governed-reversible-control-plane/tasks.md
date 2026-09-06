# Tasks — Agent-Governed Reversible Control Plane

Status legend: `[ ]` pending, `[~]` in progress, `[x]` complete.

The order is dependency-driven. A task cannot be marked complete unless its negative tests exist where applicable.

## Phase 0 — Specification and guardrails

- [x] T000 Constitution: trust, least privilege, provider certification, reversible mutation and side-channel rules.
- [x] T001 Feature spec with invariants, FR/SEC requirements and Definition of Done.
- [x] T002 Research decisions from attached competitor samples only.
- [x] T003 Normalized site-local data model for agents/subjects/grants/approvals/mutations/budgets.
- [x] T004 Architecture/rollout plan.
- [x] T005 Ability/integration contracts.
- [ ] T006 Add spec-consistency CI that checks required spec files and security invariants referenced by implementation.

## Phase 1 — Governance schema foundation

### T010 — Add `MAD4B_SCP_Schema`

- [ ] create all six normalized tables via `dbDelta()`;
- [ ] `SCHEMA_VERSION = 2`;
- [ ] table helpers use current site `$wpdb->prefix`;
- [ ] activation/boot upgrade is idempotent;
- [ ] no default agent, subject, grant or approval is created;
- [ ] migration failure exposes blocker and mutation fails closed.

Tests:
- [ ] fresh install tables exist;
- [ ] repeated migration no destructive drift;
- [ ] no authority rows after migration;
- [ ] malformed/partial schema readiness reports blocked.

### T011 — Bootstrap dependency order

- [ ] load schema/identity/registry/authorization before abilities/adapters;
- [ ] preserve PHP 7.4 syntax;
- [ ] plugin activation upgrades schema before setting final installed version.

## Phase 2 — Identity and exact grants

### T020 — `MAD4B_SCP_Identity_Context`

- [ ] normalized validated context object;
- [ ] `mad4b_scp_authenticated_subject_context` filter;
- [ ] request ID generation/reuse;
- [ ] reject/redact raw token/authorization/password/secret fields;
- [ ] support exact scopes only;
- [ ] deterministic subject fingerprint calculation.

Negative tests:
- [ ] missing subject on mutation context denied;
- [ ] malformed fingerprint denied;
- [ ] raw bearer secret field rejected;
- [ ] wildcard token scope rejected for Production mutation.

### T021 — `MAD4B_SCP_Agent_Registry`

- [ ] create/update/disable agents;
- [ ] optimistic `revision` guard;
- [ ] bind/disable subject fingerprints;
- [ ] exact grant/revoke;
- [ ] deny precedence;
- [ ] no hard-delete first implementation.

Negative tests:
- [ ] duplicate subject cannot bind to second agent;
- [ ] wildcard ability grant rejected;
- [ ] disabled agent resolves no authority;
- [ ] unknown server/ability grant rejected;
- [ ] environment mismatch denies grant.

### T022 — Effective permission graph

- [ ] central `MAD4B_SCP_Authorization` decision object;
- [ ] NHI grant ∩ token scope ∩ server ∩ provider ∩ WP capability ∩ resource policy;
- [ ] audit allow/deny decisions with safe detail;
- [ ] `effective_access()` simulation with no budget/ticket consumption.

### T023 — Integrate core mutation wrapper

- [ ] keep existing WP capability first;
- [ ] keep global mutation switch;
- [ ] require resolved NHI/exact grant for MCP/external mutations once mutation is enabled;
- [ ] preserve masked external permission error behavior while audit records precise denial.

Runtime tests:
- [ ] global mutation OFF still denies before side effects;
- [ ] forced global ON + no NHI denied;
- [ ] exact agent grant passes NHI layer;
- [ ] unrelated ability denied.

### T024 — Integrate adapter base

- [ ] adapter mutation wrapper passes provider/ability/server/input/target context centrally;
- [ ] no adapter-specific NHI logic;
- [ ] provider certification remains mandatory and cannot be widened by grants.

## Phase 3 — Governance visibility

### T030 — `mad4b/runtime-authority-status`

- [ ] schema readiness;
- [ ] mutation global status;
- [ ] enabled agents/subjects/grant counts;
- [ ] wildcard/duplicate blockers;
- [ ] approval/mutation storage readiness;
- [ ] side-channel blockers placeholder initially.

### T031 — `mad4b/agent-list`

- [ ] admin-only read;
- [ ] bounded listing;
- [ ] no secret/raw subject output.

### T032 — `mad4b/agent-effective-access`

- [ ] admin-only simulation;
- [ ] show provider status, impact and approval requirement;
- [ ] no execution/budget side effect.

## Phase 4 — Approval tickets

### T040 — Canonicalizer

- [ ] stable recursively sorted object JSON;
- [ ] preserve arrays;
- [ ] size/depth limits;
- [ ] reject non-scalar unsupported values;
- [ ] UTF-8 safe;
- [ ] SHA-256 envelope hash.

Tests:
- [ ] key order does not alter hash;
- [ ] array order does alter hash;
- [ ] one payload field change alters hash;
- [ ] oversized/deep input denied.

### T041 — `MAD4B_SCP_Approval_Tickets`

- [ ] pending create;
- [ ] admin approve/revoke;
- [ ] bounded TTL;
- [ ] atomic approved→used consume;
- [ ] class separation mutation/breakglass/recovery;
- [ ] exact NHI/ability/provider/target/payload match.

Negative tests:
- [ ] replay denied;
- [ ] expiry denied;
- [ ] wrong NHI denied;
- [ ] wrong payload denied;
- [ ] normal ticket cannot authorize breakglass.

### T042 — Impact policy registry

- [ ] read/low/high/exceptional;
- [ ] central hard minimums for plugin lifecycle, DB update, Elementor legacy, Flow execution, non-reversible/bulk writes;
- [ ] provider cannot silently lower hard minimum.

### T043 — `mad4b/approval-plan`

- [ ] no provider mutation;
- [ ] requires enabled agent/exact grant;
- [ ] returns pending ticket/hash/preconditions;
- [ ] never auto-approves.

## Phase 5 — Mutation envelope and undo

### T050 — `MAD4B_SCP_Mutation_Manager`

- [ ] durable mutation lifecycle records;
- [ ] target/before/after fingerprint handling;
- [ ] bounded rollback payload;
- [ ] read-after-write verification;
- [ ] parent lineage.

### T051 — Reversible contract: content post update first

Use `mad4b/content-update-post` as first reversible pilot because it already has `expected_modified_gmt` and public WordPress APIs.

- [ ] snapshot selected mutable fields;
- [ ] before fingerprint;
- [ ] execute through `wp_update_post` existing path;
- [ ] readback/after fingerprint;
- [ ] restore through `wp_update_post`, not raw DB;
- [ ] guard restore against after-state drift.

### T052 — `mad4b/mutation-get`

- [ ] bounded metadata response;
- [ ] no rollback payload by default.

### T053 — `mad4b/mutation-undo`

- [ ] current authorization rerun;
- [ ] expiry;
- [ ] after-state match;
- [ ] restore;
- [ ] verify before-state;
- [ ] child recovery mutation record.

Negative tests:
- [ ] drift after mutation blocks undo;
- [ ] expired undo blocks;
- [ ] non-reversible blocks;
- [ ] unauthorized actor blocks;
- [ ] failed restore not reported success.

### T054 — Extend reversible adapters deliberately

Order after pilot is green:
1. selected Media metadata;
2. Rank Math allowlisted fields;
3. Polylang language assignment;
4. WooCommerce bounded product fields;
5. JetEngine certified fields;
6. Elementor only where provider/native contract is certified.

Plugin lifecycle, DB update and Flow execution remain high-impact and may be non-reversible unless explicit provider-safe restore exists.

## Phase 6 — Blast-radius controls

### T060 — Budget config/store

- [ ] requests/mutations/affected_objects/external_actions;
- [ ] sane bounds;
- [ ] disabled by explicit config only.

### T061 — Runtime counters

- [ ] concurrency-aware counters;
- [ ] check before side effects;
- [ ] reset/window metadata;
- [ ] no unbounded option growth.

### T062 — Authorization integration

- [ ] budgets included in decision object;
- [ ] approval ticket is not consumed when budget already denies.

## Phase 7 — MCP peer side-channel governance

### T070 — Peer inventory

- [ ] inventory known registered MCP servers/Ability exporters using runtime evidence available from WordPress/MCP Adapter;
- [ ] peer classification policy;
- [ ] no auto-disable.

### T071 — Self-test blocker

- [ ] unknown privileged peer -> `mcp_write_side_channel_detected`;
- [ ] read-only/certified federated peer -> non-blocking with evidence;
- [ ] document limitations where runtime cannot prove peer write capability.

## Phase 8 — Audit storage hardening

### T080 — Append-only transactional audit table

- [ ] migrate new events to normalized append-only table;
- [ ] preserve hash-chain verification;
- [ ] safe migration/read of legacy bounded option evidence;
- [ ] mutation/approval/NHI references;
- [ ] retention policy does not silently destroy sole evidence.

### T081 — Optional external sink interface

- [ ] interface/filter only first if external implementation deferred;
- [ ] failure policy explicit: external sink failure may alert/degrade but must not leak secrets.

## Phase 9 — Admin product UX

### T090 — Overview/runtime health
### T091 — Agents/NHI
### T092 — Effective access preview
### T093 — Grants/resource constraints
### T094 — Pending approvals
### T095 — Mutation/undo history
### T096 — Provider/runtime certification
### T097 — Diagnostics/side-channel blockers

Every admin mutation action requires `manage_options` or narrower reviewed capability plus WordPress nonce.

## Phase 10 — CI and staging certification

### T100 — `agent-governance-contract.py`

Static negative assertions for schema/no-default-authority/wildcard denial/secret-url ban/approval/undo contracts.

### T101 — Runtime integration expansion

Matrix WordPress 6.9 + latest:
- migration;
- no default authority;
- NHI exact grants;
- permission intersection;
- approval replay/expiry;
- reversible post update and undo;
- deliberate post drift blocks undo.

### T102 — Packaging

- exact feature head in manifest;
- runtime migration/classes included;
- tests/specs excluded or retained according to packaging policy, but never required for runtime;
- SHA256SUMS independent verification.

### T103 — Real target staging

- exact MCP transport subject bridge evidence;
- create dedicated staging NHI with minimum grants;
- runtime authority self-test PASS;
- benign content mutation;
- readback verification;
- undo PASS;
- stale mutation rejection;
- deliberate after-state drift -> undo DENY;
- peer MCP inventory;
- all mutation gates returned OFF after certification unless explicitly continuing controlled staging.

## Production gate

Production write remains NO-GO until:
- [ ] all P0 tasks complete;
- [ ] T010–T071 complete and CI green;
- [ ] audit evidence adequate for Production decision (T080 strongly preferred before broad writes);
- [ ] target staging T103 PASS;
- [ ] exact provider/runtime certification PASS;
- [ ] no side-channel blocker;
- [ ] at least one enabled Production NHI with exact non-wildcard minimal grants;
- [ ] approval and recovery policy validated;
- [ ] explicit operator authorization to enable mutation.

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
- [ ] D011 SIEM/WORM connector implementations.
- [ ] D012 anomaly/ML prompt-injection classifier as defense-in-depth.
- [ ] D013 mobile/SaaS dashboard/billing/marketplace.

Never planned inside normal WordPress MCP:
- arbitrary PHP execution;
- shell execution;
- live source editor;
- unrestricted raw filesystem/source mutation;
- host SSH/system service control.
