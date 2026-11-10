# Tasks: MAD4B FAORE

**Feature**: 001-federated-adaptive-operations
**Status**: Ready for implementation. Boxes represent work not yet executed.
**Execution rule**: Reuse existing components and enforce local admission before any effects.

## Phase P0 — contracts, identity and durable transport
- [ ] T001 [P0] Inventory repository and runtime ownership for Company Manager, site Control Plane, Broker 305/306/312/313, Native Executor, release manager and current journals; record verified paths and gaps in `research.md`.
- [x] T002 [P0] Define machine-readable JSON Schemas for six FCP envelopes with explicit compatibility and error vocabulary; add positive/negative fixtures.
- [ ] T003 [P0] Introduce ParentOperation/ChildOperation/DeliveryAttempt IDs mapped onto current Broker and existing job state, without double-authoritative ledgers.
- [ ] T004 [P0] Add stable site UUID + environment + company binding and tenant-negative tests before receipt/write paths.
- [ ] T005 [P0] Implement company-side durable inbox/outbox, ACK, sequence, retry/backoff, dead-letter and crash/restart resume.
- [ ] T006 [P0] Implement duplicate delivery/replay detection and idempotent effects; prove physical retry does not repeat logical execution.
- [ ] T007 [P0] Integrate local journal and central receipt ingestion; support evidence states separate from job status.
- [ ] T008 [P0] Acceptance test: read-only child round-trip with exact IDs, 313/readback and verified central report.

## Phase P1 — local authority and provider execution
- [ ] T009 [P1] Advertise dynamic capabilities/skill versions/health from Local Control Plane.
- [ ] T010 [P1] Implement local admission decision and precise error codes with no alternate-channel bypass.
- [ ] T011 [P1] Separate Native Executor availability, write authorization, operation admission and execution receipt status.
- [ ] T012 [P1] Map approved read/write to local executors and retain Broker receipt compatibility.
- [ ] T013 [P1] Acceptance matrix: rejected cross-site operation, missing capability, provider offline, permitted operation and readback mismatch.

## Phase P2 — sources, compatibility and graph reconciliation
- [ ] T014 [P2] Inspect canonical Source Release Manager and company-owned override representation.
- [ ] T015 [P2] Add generation manifest/digest/compatible range and current/desired/pending/deferred/rollback states.
- [ ] T016 [P2] Add Component/Company/Fleet dependency graph and affected-company selector by impact class.
- [ ] T017 [P2] Build Coolify-authorized release apply/rollback receipts while preserving company-specific settings and data.
- [ ] T018 [P2] Implement source quarantine recycle/cleanup without stealing foreign-owner attachments.
- [ ] T019 [P2] Acceptance: selective release, incompatible deferral, no duplicate apply, rollback generation verified.

## Phase P3 — Wayfatours pilot
- [ ] T020 [P3] Verify Wayfatours site origin, UUID, environment, local Control Plane and Coolify resource.
- [ ] T021 [P3] Run Federated Read: central dispatch -> local inbox/admission -> read -> 313 -> central verification.
- [ ] T022 [P3] Run separate narrowly scoped authorized write only on local admission; compare readback.
- [ ] T023 [P3] Reconcile `mad4b-experience` and remaining approved drift using source ownership graph.
- [ ] T024 [P3] Generate Wayfatours execution report showing Observed/Verified/Unverified/Stale honestly.

## Phase P4 — Company Manager fleet rollout
- [ ] T025 [P4] Add Company Profile Local/Central Managed/Hybrid with safe migration and mode independence from grants.
- [ ] T026 [P4] Add Company Connections, Fleet Task Manager, Operations Timeline and Company Operations panel.
- [ ] T027 [P4] Add Source & Reconcile, Skills & Policies, Fleet Reports, with redacted metadata-only aggregation.
- [ ] T028 [P4] Verify ARE/Wayfatours locale-specific child plans and true partial-result propagation.
- [ ] T029 [P4] Roll out to a second staging company; measure stale evidence, queue backlog and retry health.
- [ ] T030 [P4] Prepare controlled production eligibility report and rollback runbook; do not self-grant authority.

## Cross-cutting quality gates
- [ ] T031 [QA] Validate all FCP envelope schemas and state transitions; ensure backward compatibility with 305/306/312/313.
- [ ] T032 [QA] Prove no foreign tenant, denied operation or unapproved source can be executed via fallback.
- [ ] T033 [QA] Prove offline recovery, idempotency and no silent loss of acknowledged jobs.
- [ ] T034 [QA] Verify company data/secrets omitted from central reports, request/response logs and example payloads.
- [ ] T035 [QA] Check all implementation claims include real receipt + readback, never invented success.

## Dependency chain
T001 -> T002/T003/T004 -> T005/T006/T007 -> T008 -> T009..T013 -> T014..T019 -> T020..T024 -> T025..T030; QA tasks are gates at each phase.

## Implementation checkpoint (2026-11-05)
- T001: latest central source inventory performed; source ownership and config parity remain open.
- T003–T007: local Broker reference implemented; actual 305/306/312/313 adapter remains open.
- T009: source-level site capability read model implemented; not deployed or connected to external sender.
- T015–T016: non-deploying release preview and graph tested; rollout and rollback remain open.
- T026: source-level Company Manager fleet observation exists; task builder, timeline and deployment remain open.
- All unchecked tasks remain open. See progress-20261105.md for exact evidence.
