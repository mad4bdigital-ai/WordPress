# Implementation Plan: MAD4B FAORE

**Feature**: 001-federated-adaptive-operations
**Plan date**: 2026-11-05
**Baseline**: WordPress Git master 5c72e4893e36e992f056c90cfc75908b3a8f7785 (cloned 2026-11-05)
**Target**: Reuse existing MAD4B portfolio management, site Control Plane, Broker, Native Executor, Source Release Manager and Coolify.
**State**: Planning only — no runtime execution.

## Technical context
PHP / WordPress plugins and MU plugins; current MAD4B control-plane code in `wp-content/plugins/mad4b-site-control-plane`; Broker and governance adapters in `ops/`. Live Company Portfolio Manager is separately installed at Central staging, but canonical Git ownership must be verified before editing. Data persistence must use the current per-company operation journal/queue instead of creating an independently conflicting state store.

## Constitution gate
- Local admission always prevails over central dispatch; never retry via other channel to bypass refusal.
- Keep company business data local; central uses operational metadata and evidence refs.
- Reuse existing Broker 305/306/312/313 and Coolify; avoid duplicate deploy system.
- Mandatory tenant/auth boundaries and readback cannot be optional. Other governance advisory only where safely supported.
- Implement incremental releases and version compatibility; preserve ownership and rollback.

## Architecture
```
Company Management: Fleet Registry + Planner + Dispatcher + Aggregator + Reports
          | FCP envelopes, transport receipts, correlation IDs
          v
Local Company Plane: Capability Registry -> Durable Inbox -> Local Admission
       -> Skill Resolver -> Native Executor / Coolify Adapter
       -> Readback -> Journal -> Durable Outbox -> Central Fleet Evidence
```

Central operations never directly execute local writes on a denied scope. Local and central job authors share one logical operation namespace but have separate origin and delivery identities.

## Work packages and dependency order

### P0 — Shared Kernel + Federated Contracts
1. Live inventory the existing operation IDs, 305/306/312/313 receipts, journal, identity binding, capabilities and source ownership. Record code owner and compatibility map.
2. Define FCP six versioned schemas (see contracts folder) and deterministic admission/error vocabulary.
3. Add mapping from current Broker IDs into Parent/Child operation and delivery identity; keep current readers/writers compatible.
4. Implement durable company inbox/outbox persistence and transport ACK/replay/sequence/retry/DLQ.
5. Add contract fixtures: matching tenant, foreign tenant, duplicate delivery, out-of-order receipt, unsupported version.

**Exit**: A read-only operation round-trips through the local company to central with stable identity, correct receipt and no duplicate execution.

### P1 — Execution Authority
1. Surface provider readiness, local admission and 313 result separately; do not equate presence of a tool with authorization.
2. Build dynamic discovery for Local Skills/Providers and exact company/environment/site binding.
3. Route admitted read requests to local Control Plane; write goes through existing Native Executor.
4. Preserve safe alternative provider fallback only for provider unavailability and only when the *same authorized operation scope* permits it; never bypass denial.
5. Emit exact failure codes to Company Management.

**Exit**: Denied write never starts; admitted read yields 313+readback; retry stays idempotent.

### P2 — Adaptive Source & Reconciliation
1. Inventory Source Release Manager ownership, source generations and company-specific overlays.
2. Add release manifest: digest, version, dependency impact, compatible ranges, desired/current/rollback generation.
3. Build Component/Company/Fleet dependency graph; classify Runtime/Schema/Data/Presentation/Security change impact.
4. Design per-company compatibility admission, Coolify apply, verification, rollback and local receipt.
5. Add quarantine source cleanup/recycle policy without transferring foreign-owned artifacts.

**Exit**: Reconcile preview selects impacted companies and preserves company overrides; rollback evidence matches runtime.

### P3 — Wayfatours end-to-end
1. Verify Wayfatours exact enrolled site UUID, origin, environment and Coolify resource before requests.
2. Discover live local capabilities, skills, providers and source access.
3. Dispatch a read-only job from Company Management to local inbox, collect admission, 313 and readback.
4. Run a narrowly scoped write only after the existing local authority admits it; compare live readback.
5. Apply approved reconciliation (including `mad4b-experience` parity) only through the release engine.
6. Report local and central timeline states with no fabricated results.

**Exit**: One real, evidence-backed read and one separately admitted limited write (or an accurate rejected state). No forced success.

### P4 — Fleet rollout
1. Enable Local/Central Managed/Hybrid in Company Profile with migration-safe defaults.
2. Add fleet task builder, company operations pane, operations timeline and aggregate evidence report.
3. Roll out to a second Staging company, then eligible environments progressively.
4. Add self-healing for transport delivery and non-privileged recovery only, with audit/alerting for repeated failure.
5. Measure retry rates, stale evidence, queue backlog and reconciliation drift.

**Exit**: Multi-company run reports partial/failed/unreachable/verified child states accurately.

## Persistence and transport decisions (pending P0 inventory)
- Use existing WordPress/Broker local state if adequate; introduce additive schema migrations only.
- At-least-once transport with deduplication key = company + environment + child_operation_id + effect_key.
- delivery_id identifies an attempt; sequence_number and journal offset allow resume.
- Local evidence is authoritative for execution; central verification compares receipt and readback.
- Never send customer/brand data in fleet summaries; secret values must not be logged.
- Protocol major version changes are explicit and can coexist during transition.

## Testing approach
Hermetic contract schema checks; tenant-denial matrix; real local Control Plane readback; duplicate/reordered delivery tests; restart/reconnect queue tests; Woo/WordPress capability mismatch tests; Source Release dry-run and rollback tests; Company Manager UI state parity tests; Staging then authorized Production. Do not skip admission for test convenience.

## Deployment and rollback
Commit code to a scoped feature branch; run read-only inspection first. Build/deploy through existing approved Coolify resource; confirm generation and site identity before each apply; preserve previous generation and per-company overrides. On readback failure mark Unverified/Failed and use the existing rollback path; never claim Verified from an HTTP 200 alone.

## Open technical decisions
Exact canonical Company Manager plugin repository location; existing Broker journal schema; FCP event transport (REST/queue broker); minimal required authentication and nonce sequence; contract version range; retention/DLQ policy; source release manifest ownership; production provider status. These must be grounded in live inventory, not guessed.
