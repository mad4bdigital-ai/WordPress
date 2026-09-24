# Data Model — Agent-Governed Reversible Control Plane

Status: Normative schema contract aligned with current implementation
Storage scope: site-local WordPress database tables
Schema version: `9`
Encoding: UTF-8 / JSON text only where structured extension fields are required
Secret policy: no plaintext bearer/OAuth credential persistence

Schema v9 contains fifteen normalized MAD4B tables. Table names are resolved with the current site `$wpdb->prefix`; migration uses `dbDelta()` and never creates enabled agents, grants, subjects or approvals automatically. It preserves the v6 exact Site Profile/candidate approval binding, so clone, origin/environment, profile-policy or deployed-build drift cannot inherit existing governed-write authority, and adds Feature 007 durable Content Job, event, lease, idempotency, outbox and inbox storage. Durable recovery remains fail-closed: expired pending work is not silently reused without explicit reconciliation evidence.

## Table 1 — `{prefix}mad4b_scp_agents`

Purpose: stable NHI identity records.

Key columns:
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `public_id CHAR(36) NOT NULL UNIQUE`
- `slug VARCHAR(191) NOT NULL UNIQUE`
- `label VARCHAR(191) NOT NULL`
- `status VARCHAR(20) NOT NULL DEFAULT 'disabled'`
- `wp_user_id BIGINT UNSIGNED NULL`
- `environment VARCHAR(32) NOT NULL DEFAULT 'unknown'`
- `revision BIGINT UNSIGNED NOT NULL DEFAULT 1`
- `created_by`, `created_at`, `updated_at`

Invariants:
- disable preserves history;
- `public_id` is immutable;
- no hard-delete-first authority model;
- environment and revision are validated by service logic.

## Table 2 — `{prefix}mad4b_scp_agent_subjects`

Purpose: bind upstream authenticated transport subjects to one NHI without storing credentials.

Key columns:
- `agent_id`
- `subject_type VARCHAR(64)`
- `subject_fingerprint CHAR(64)`
- `label`
- `status`
- timestamps

Indexes include unique `(subject_type, subject_fingerprint)` and `(agent_id, status)`.

Invariants:
- only normalized fingerprint/non-secret evidence persists;
- raw bearer/access/refresh secrets are rejected;
- ambiguous subject resolution is a blocker even if DB uniqueness should prevent it.

## Table 3 — `{prefix}mad4b_scp_agent_grants`

Purpose: exact server/ability/provider authority.

Key columns:
- `agent_id`
- `effect allow|deny`
- `server_id`
- `ability_name`
- `provider`
- `resource_schema_version`
- bounded `resource_constraints` JSON
- `environment`
- creator/timestamps

Unique authority key: `(agent_id, effect, server_id, ability_name, provider, environment)`.

Invariants:
- wildcard ability names are rejected;
- deny wins over allow;
- server/ability/provider must match actual mounted runtime authority;
- unknown or unresolved resource constraints deny.

## Table 4 — `{prefix}mad4b_scp_approval_tickets`

Purpose: one exact, short-lived, single-use approval for high/exceptional impact plus immutable exact-operation, tenant-profile and deployed-build binding for the governed human-decision flow.

Key columns:
- `ticket_id CHAR(36) UNIQUE`
- `ticket_class mutation|breakglass|recovery`
- `agent_id`
- `server_id`
- `ability_name`
- `provider`
- `target_fingerprint`
- `payload_sha256`
- stored lifecycle `status pending|approved|executing|used|failed|revoked`
- `reason`
- `approved_by`, `approved_at`, `expires_at`, `used_at`, `created_at`
- `candidate_binding_contract`
- `candidate_sha CHAR(40)`
- `build_fingerprint CHAR(64)`
- `binding_environment`
- `binding_host`
- `site_uuid CHAR(36)`
- `site_profile_revision BIGINT UNSIGNED`
- `site_profile_digest CHAR(64)`
- `bound_at`

Read-model indexes:
- `decision_inbox (status, expires_at, id)`
- `candidate_inbox (candidate_sha, build_fingerprint, status, expires_at)`
- `site_profile_inbox (site_uuid, site_profile_revision, status, expires_at)`

Invariants:
- the hash covers the canonical approval envelope, not raw request text;
- a normal governed remote mutation approval uses `mad4b.approval-candidate-binding.v2` and is bound durably to the exact Site Profile UUID, revision and digest together with candidate SHA, build fingerprint, environment and exact enrolled origin/host;
- the Site Profile binding is independent from operation payload, NHI/grant authority and deployed-build identity; all four axes must remain exact at decision and execution time;
- cloning the database to another domain, moving between environments, editing the Site Profile policy, changing the Site Profile revision/digest, or deploying a different build invalidates the existing approval instead of carrying authority forward;
- successful execution claims atomically transition `approved -> executing`; successful completion finalizes `executing -> used`, while an execution failure finalizes `executing -> failed`;
- replay of `executing`, `used` or `failed` tickets is denied;
- expiry is enforced against `expires_at` at decision and execution boundaries;
- `expired` and `stale` are derived read-model states for display/filtering and are not written merely because the console GET page was opened;
- the normal decision inbox includes only fresh pending `mutation` tickets for `mad4b-write` whose tenant profile and candidate/build bindings exactly match the current governed runtime;
- legacy v1 candidate bindings never silently gain tenant authority; an incomplete legacy ticket is stale/fail-closed until a new exact v2 plan is created;
- replay, expiry, target/payload mismatch, Site Profile drift, candidate/build drift and class mismatch fail closed;
- approval never bypasses provider, capability, stale-state, peer, physical-schema or budget gates.

## Table 5 — `{prefix}mad4b_scp_mutations`

Purpose: durable mutation/verification/undo envelope.

Key columns:
- `mutation_id CHAR(36) UNIQUE`
- `request_id`
- `parent_mutation_id`
- `agent_id`
- `subject_type`, `subject_fingerprint`, `wp_user_id`
- `server_id`, `ability_name`, `provider`, `provider_version`
- `target_type`, `target_id`
- `approval_ticket_id`
- `impact`
- lifecycle `status`
- `reversible`
- `before_sha256`, `after_sha256`
- bounded `rollback_payload` and `rollback_payload_sha256`
- `undo_expires_at`
- `verification_code`, `error_code`
- timestamps

Invariants:
- mutation envelope persists before the provider write on certified reversible paths;
- `verified` requires read-after-write validation;
- rollback payload is bounded and integrity protected;
- normal inspection does not expose rollback payload;
- undo requires current state == recorded after-state and creates a child recovery record.

## Table 6 — `{prefix}mad4b_scp_agent_budgets`

Purpose: per-agent blast-radius configuration.

Key columns:
- `agent_id`
- `budget_type requests|mutations|affected_objects|external_actions`
- `window_seconds`
- `max_count`
- `enabled`
- `updated_by`, `updated_at`

Unique `(agent_id, budget_type)`.

## Table 7 — `{prefix}mad4b_scp_agent_budget_windows`

Purpose: transactional runtime budget counters.

Key columns:
- `agent_id`
- `budget_type`
- `window_start`
- `window_seconds`
- `used_count`
- timestamps

Unique `(agent_id, budget_type, window_start)` with cleanup and agent-window indexes.

Runtime invariants:
- counters live in DB rows, not unbounded options/cache counters;
- reservation uses transactions and row locking;
- two-process contention cannot oversubscribe a configured budget;
- exhausted budget denies before approval consumption/provider side effect;
- rejected approval rolls the active reservation back;
- cleanup is bounded.

## Table 8 — `{prefix}mad4b_scp_audit_events`

Purpose: immutable append-only security evidence.

Key columns:
- `chain_name`
- monotonic `sequence`
- `event_id CHAR(36) UNIQUE`
- `occurred_at`
- `request_id`
- `user_id`
- `ability`
- `status`
- bounded/redacted `summary_json`
- `previous_hash`
- `entry_hash`
- `created_at`

Unique `(chain_name, sequence)` plus event/request/ability/hash indexes.

Invariants:
- normal runtime only appends events; no update/delete event path;
- entry hash links canonical event material to prior hash;
- concurrent writers serialize through the locked head;
- tamper is detectable by chain verification.

## Table 9 — `{prefix}mad4b_scp_audit_heads`

Purpose: singleton chain head plus legacy-history anchor.

Key columns:
- `chain_name PRIMARY KEY`
- current `sequence`
- current `entry_hash`
- `legacy_anchor_sha256`
- `legacy_chain_valid`
- `legacy_entry_count`
- timestamps

Invariants:
- head is initialized safely before operational transactions;
- append uses `SELECT ... FOR UPDATE` against the existing head;
- legacy option evidence is retained read-only and cryptographically anchored;
- legacy drift makes integrity verification fail.

## Table 10 — `{prefix}mad4b_content_jobs`

Purpose: durable Content Job aggregate state for Feature 007.

Key columns:
- `job_id CHAR(36) UNIQUE`
- `site_uuid`, `brand_id`
- `state`, `stage`
- `current_artifact_id`
- `job_revision BIGINT UNSIGNED DEFAULT 1`
- target post identity, quality/error fields and timestamps

Invariants:
- mutable job state carries an explicit revision;
- writes use expected revision/state rather than last-write-wins;
- current artifact identity and job lifecycle are treated as durable aggregate state.

## Table 11 — `{prefix}mad4b_content_job_events`

Purpose: append durable lifecycle evidence for Content Jobs.

Key columns:
- `event_id CHAR(36) UNIQUE`
- `job_id`
- monotonic per-job `sequence`
- `event_type`
- previous/new state and stage
- `plan_sha256`, `artifact_id`, `provider_id`
- `previous_entry_sha256`, `entry_sha256`
- `created_at`

Indexes include unique `(job_id, sequence)`.

Invariants:
- job events are append-only evidence;
- event ordering is explicit per job;
- state/event transitions must not expose half-committed logical transitions.

## Table 12 — `{prefix}mad4b_work_leases`

Purpose: fenced ownership of long-running work.

Key columns:
- `work_id CHAR(36) UNIQUE`
- aggregate type/id
- `worker_id`
- `lease_epoch`
- `expected_aggregate_revision`
- `status`
- acquired/heartbeat/expiry timestamps
- `reconciliation_ref`

Invariants:
- only an active expired lease may be reclaimed;
- reclaim requires bounded reconciliation evidence;
- terminal leases are never resurrected;
- stale worker/epoch/revision tokens fail closed.

## Table 13 — `{prefix}mad4b_idempotency`

Purpose: effect-once protection for externally retryable writes.

Key columns:
- `scope_key CHAR(64)`
- `idempotency_key`
- `request_sha256`
- `claim_epoch BIGINT UNSIGNED DEFAULT 1`
- `status`
- result JSON/hash
- `reconciliation_ref`
- `expires_at`

Unique authority key: `(scope_key, idempotency_key)`.

Invariants:
- same key + same request hash may replay stored completion evidence;
- same key + different request hash is a hard conflict;
- expired pending records require explicit reconciliation before reclaim;
- every reclaim increments `claim_epoch`, and stale claim epochs cannot complete or reuse the record;
- retention exceeds the supported retry/replay horizon.

## Table 14 — `{prefix}mad4b_execution_outbox`

Purpose: durable provider execution intent before asynchronous delivery.

Key columns:
- `outbox_id CHAR(36) UNIQUE`
- `job_id`, expected job revision
- provider/capability identity
- `workflow_plan_sha256`
- `idempotency_key`, `request_sha256`
- payload, status, attempt count
- provider execution ref, error class, availability/timestamps

Unique provider delivery identity: `(provider_id, idempotency_key)`.

Invariants:
- delivery intent is persisted before provider execution;
- duplicate provider/idempotency identity with a different request hash is denied;
- no claim of exactly-once network delivery is made.

## Table 15 — `{prefix}mad4b_execution_inbox`

Purpose: deduplicate provider callbacks/events.

Key columns:
- `provider_id`
- `provider_event_id`
- `job_id`
- `payload_sha256`
- `status`
- `provider_execution_ref`
- `result_ref`
- received/processed timestamps

Unique event identity: `(provider_id, provider_event_id)`.

Invariants:
- repeated same provider event is idempotent only when payload and job identity match;
- the same event ID cannot be rebound to another job or conflicting execution reference;
- callback ordering is validated by higher-level job/provider contracts when order matters.

## Transport subject context

Runtime normalized structure may contain:

```json
{
  "authenticated": true,
  "subject_type": "oauth_client",
  "subject_fingerprint": "64hex",
  "token_scopes": ["ability:mad4b/content-update-post"],
  "approval_ticket_id": "opaque-uuid-if-present",
  "auth_method": "mcp-adapter",
  "wp_user_id": 123,
  "request_id": "..."
}
```

Rules:
- supplied through `mad4b_scp_authenticated_subject_context` after upstream authentication;
- raw authorization/token/password/secret material is forbidden;
- wildcard token scopes are rejected;
- final normalized context represents one authenticated subject or failure.

## Canonical approval envelope

The base exact operation remains `mad4b.approval.v1`. For a normal governed remote `mad4b-write` mutation, the canonical envelope additionally includes the exact Site Profile binding:

```json
{
  "contract": "mad4b.approval.v1",
  "site": "https://exact-enrolled-origin.example",
  "agent_public_id": "...",
  "server_id": "mad4b-write",
  "ability": "elementor/update-widget-settings",
  "provider": "elementor",
  "target": "...",
  "ticket_class": "mutation",
  "input": {},
  "site_profile_binding": {
    "site_uuid": "uuid",
    "profile_revision": 7,
    "profile_digest": "64hex",
    "environment": "staging",
    "origin": "https://exact-enrolled-origin.example"
  }
}
```

Canonicalization:
- recursively sort object keys;
- preserve array order;
- normalize supported scalar values;
- reject resources/objects/non-finite values;
- bound depth and canonical byte size;
- hash canonical UTF-8 JSON with SHA-256.

## Transaction ownership and audit dispatch

Budget enforcement owns the operational transaction when active. Audit can join that transaction using explicit service-owned transaction state; it does not probe database-specific session variables.

Joined audit sink dispatch occurs only after explicit transaction commit. Explicit rollback drops pending dispatch. External listeners consume `mad4b_scp_audit_committed`; an external SIEM/WORM backend remains a separate implementation.

## Migration strategy

Schema version is stored in option `mad4b_scp_schema_version` and current expected version is `9`. Schema v9 is governed by migration contract `mad4b.schema-migration.v1` with migration ID `20260924-feature007-durable-execution-v9`.

Migration declaration:
- prerequisite schema identities: fresh install `0`, and supported prior/current versions `6|7|8|9`; a future or otherwise unsupported version fails closed instead of being downgraded;
- forward operation: additive `dbDelta()` creation/update of MAD4B-prefixed tables, columns and indexes only;
- rollback/forward-fix strategy: forward-fix only; additive v9 objects are preserved so older code can ignore the new surfaces rather than requiring destructive rollback;
- expected locks/downtime: bounded metadata DDL; no maintenance mode is assumed;
- data-volume assumption: the six Feature 007 durable tables are new or sparse while existing governance rows are preserved;
- preflight: supported prerequisite version, usable WordPress DB handle, non-empty site prefix and no future-schema downgrade;
- post-verification: deep physical integrity, approval-binding columns, durable columns and required unique indexes;
- evidence: deterministic migration-contract SHA-256, target integrity token, physical-integrity SHA-256 and durable `mad4b.schema-migration-receipt.v1`;
- partial failure: target version/readiness is not accepted until deep verification, exact option readback and a finalized receipt succeed; retry remains idempotent;
- mixed-version window: v9 is additive and previous v6 code does not consume the new durable surfaces;
- authority widening: forbidden; migration does not create/enable NHI subjects, grants, approvals, provider promotion or Production authority.

Activation/boot rules:
1. A healthy already-finalized v9 schema short-circuits without repeated DDL.
2. Otherwise migration preflight runs before `dbDelta()`; unsupported/future schema identity fails closed.
3. `dbDelta()` creates/updates only MAD4B-prefixed tables and the operation is idempotent.
4. Migration never auto-creates enabled NHI authority, and existing global mutation enablement never implies NHI authority.
5. Schema v9 preserves the v6 Site Profile approval bindings and adds six durable Feature 007 tables: Content Jobs, Job Events, Work Leases, Idempotency, Execution Outbox and Execution Inbox.
6. The idempotency table includes `claim_epoch` and `reconciliation_ref`; reclaim increments the epoch after verified reconciliation, stale claims are fenced, expired active leases require reconciliation, and terminal leases cannot be resurrected.
7. Legacy option-based or v1 candidate bindings may be migrated only as compatibility evidence; incomplete tenant/profile/build binding remains stale and requires a new v2 exact plan.
8. New governed remote approval bindings are persisted on the approval row using `mad4b.approval-candidate-binding.v2`.
9. Deep physical verification must pass before any readiness marker advances. A physical-verification receipt is persisted/read back, then schema version and integrity token are persisted/read back, then a finalized receipt is persisted/read back.
10. `MAD4B_SCP_Schema::is_ready()` requires exact version, exact integrity token and a valid finalized migration receipt; missing/partial evidence therefore keeps governed mutation fail-closed.
11. Audit head/legacy anchor is initialized only after schema readiness, and no legacy capability is widened during migration.

## Retention and evidence

- agent/subject/grant history is retained through disable/revoke-oriented lifecycle rather than destructive defaults;
- approval and mutation records retain operational evidence according to future retention policy while hashes/status remain authoritative;
- append-only audit is the primary local security evidence chain;
- retention must not silently destroy the sole evidence required for authorization/mutation reconstruction;
- external WORM/SIEM export may be added through the post-commit hook without changing local authority semantics.
