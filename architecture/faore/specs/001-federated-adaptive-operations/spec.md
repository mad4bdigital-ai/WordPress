# Feature Specification: MAD4B Federated Adaptive Operations & Reconciliation Engine

**Feature**: 001-federated-adaptive-operations
**Date**: 2026-11-05
**Status**: Planned — no runtime implementation or deployment
**Basis**: User-supplied FAORE design with federated authority, task contracts and five delivery phases.

## Goal
Enable Company Management to schedule, scope and dispatch a parent operation across enrolled companies; let each local Control Plane select skills, authorize and execute its own child operation; and aggregate independently verified results without centralizing company data.

## User Stories

### US1 [P1] Company discovery and locally authorized execution
The operator chooses an enrolled company/environment and sees its actual Control Plane capabilities, skills, sources, runtime provider and health. The operator may request a scoped operation. Local admission accepts or refuses with a specific reason.
**Acceptance**: A mismatched company/site UUID/environment or absent capability is rejected before any effect; a successful read has a 313 receipt and matching readback; central cannot override a local rejection.

### US2 [P1] Multi-company parent and child jobs
The operator sends one parent job to multiple companies. Each company receives one child with scoped capability, locales and policies. For example ARE EN/AR/FR/DE/ES/IT and Wayfatours EN/ES translation audits resolve independently.
**Acceptance**: Completed, Failed, Partial, Unreachable and Verified appear independently without waiting for every child; duplicated deliveries do not cause repeated execution.

### US3 [P1] Local and offline-first operation
Local companies can run approved work without the central server. Previously admitted central work can finish through local executor when disconnected.
**Acceptance**: Inbox/outbox survive process restarts; ACK, retries/backoff, dead-letter and resume are supported; reconnect syncs receipts logically once; offline never grants new privileges.

### US4 [P2] Versioned source release and selective reconcile
Central publishes a canonical shared release/digest; company checks compatibility, owner overlays and dependencies, then performs an authorized Coolify deployment with readback/rollback.
**Acceptance**: Incompatible companies defer with precise cause; company-owned configuration survives; unrelated companies are not deployed; rollback restores prior generation with receipt.

### US5 [P2] Fleet management and evidence reporting
Company Manager supports Local/Central Managed/Hybrid modes, Fleet Task Manager, Timeline, Source & Reconcile, Skills & Policies, Reports, and per-company operations detail.
**Acceptance**: Reports distinguish Observed/Verified/Unverified/Stale, show precise refusal reasons and never include secrets, company DB or client records by default.

## Functional Requirements
- **FR-001** Reuse existing mad4b-company-portfolio-manager as federated UI, not a replacement product.
- **FR-002** Parent -> independent company-scoped Child Operations; support partial results.
- **FR-003** Discovery reports versioned live Capability Manifest (providers, health, supported operation, skill family).
- **FR-004** Company Profile modes: Local, Central Managed, Hybrid; Hybrid default only for eligible connected local planes.
- **FR-005** FCP versioned contracts: company.capabilities.v1, company.task.dispatch.v1, company.task.admission.v1, company.task.progress.v1, company.task.receipt.v1, company.runtime.snapshot.v1.
- **FR-006** Every event binds company_id, environment, site_uuid, delivery_id and contract version; task events additionally bind parent_operation_id, child_operation_id and requested_capability.
- **FR-007** Local policy and admission are authoritative. No alternate-channel retry for locally denied operations.
- **FR-008** Durable inbox/outbox, ordering, ACK, idempotency, replay detection, retry/backoff, DLQ and reconnection.
- **FR-009** Logical operation ID and delivery attempt ID remain distinct; at-least-once transport without duplicate effects.
- **FR-010** Preserve Broker 305/306/312/313 compatibility and join receipts to the common operation journal.
- **FR-011** Native Executor runs only admitted WP operations; Coolify manages runtime deployment.
- **FR-012** Source Release Manager tracks canonical digest, current/desired/rollback generation, compatibility and company overrides.
- **FR-013** Component/Company/Fleet dependency graphs with Runtime, Schema, Data, Presentation and Security impact categories.
- **FR-014** Central metadata-only reports; company-owned content, secrets, brands, customers and DBs stay local unless separately approved.
- **FR-015** Evidence states Observed/Verified/Unverified/Stale do not collapse into job status.
- **FR-016** Company Manager presents six modules plus Company Operations identity/capabilities/skills/sources/reconcile/jobs/receipts/errors.
- **FR-017** Precise error reasons replace generic WRITE_FAILED.
- **FR-018** Wayfatours reference path: Local Connection -> Identity -> Capability -> Dispatch -> Admission -> Native Read -> Receipt -> Result Sync -> limited authorized Write -> Reconcile -> Fleet Report.
- **FR-019** Mixed compatible protocol/release generations supported; incompatible capability rejected explicitly.
- **FR-020** No Verified or deployment success without matching readback and evidence.
- **FR-021** Optional certs/rights/attestation advisory when permitted; mandatory security, tenant and permission checks remain.
- **FR-022** Source release never overwrites company-owned policy/settings/brand/pricing overrides without a separately authorized migration.

## Entities
FleetCompany; CompanyEnvironment; CapabilityManifest; ParentOperation; ChildOperation; DeliveryAttempt; AdmissionDecision; JournalEntry; Receipt; RuntimeGeneration; DependencyEdge; FleetReport. Detailed definitions in data-model.md.

## Failure and recovery cases
Foreign/mismatched site UUID; offline before or after ACK; duplicate/out-of-order receipt; missing executor; failed/local-denied write; stale capabilities; conflicting local and central job; deployment interrupted after apply; unsupported generation; quarantined legacy sources; missing/invalid readback; rollback failure.

## Success criteria
- **SC-01** Wayfatours readonly child can be dispatched, admitted locally, executed, receipted (313), read back and verified centrally.
- **SC-02** Redelivery/restart produces a single logical operation and no duplicate side effects.
- **SC-03** Two companies with different language/capability sets resolve independent child plans and partial results.
- **SC-04** Offline local job finishes and receipts synchronize after reconnect.
- **SC-05** Cross-company/environment mismatch refuses with actionable code and no apply.
- **SC-06** Source graph targets only impacted companies and rollback receipts match runtime generation.
- **SC-07** Fleet report preserves evidence grades, per-child status and data minimization.

## Scope boundaries
Planning docs do not grant Production write access, issue credentials, publish code, deploy containers or replace WordPress core. Protocol transport and store are selected after live ownership inventory. No unverified implementation claims.
