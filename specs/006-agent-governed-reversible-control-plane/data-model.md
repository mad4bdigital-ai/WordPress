# Data Model — Agent-Governed Reversible Control Plane

Status: Normative schema contract aligned with current implementation
Storage scope: site-local WordPress database tables
Schema version: `5`
Encoding: UTF-8 / JSON text only where structured extension fields are required
Secret policy: no plaintext bearer/OAuth credential persistence

Schema v5 contains nine normalized MAD4B tables. Table names are resolved with the current site `$wpdb->prefix`; migration uses `dbDelta()` and never creates enabled agents, grants, subjects or approvals automatically. Schema v5 also moves exact approval candidate/build binding into the approval row itself so the human-decision read model can remain bounded without per-row option lookups.

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

Purpose: one exact, short-lived, single-use approval for high/exceptional impact plus immutable exact-candidate binding for the governed human-decision flow.

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
- `bound_at`

Read-model indexes:
- `decision_inbox (status, expires_at, id)`
- `candidate_inbox (candidate_sha, build_fingerprint, status, expires_at)`

Invariants:
- hash covers the canonical approval envelope, not raw request text;
- an exact remote Staging mutation ticket is bound durably to the candidate SHA, build fingerprint, environment and host on the same ticket row;
- successful execution claims atomically transition `approved -> executing`; successful completion finalizes `executing -> used`, while an execution failure finalizes `executing -> failed`;
- replay of `executing`, `used` or `failed` tickets is denied;
- expiry is enforced against `expires_at` at decision and execution boundaries;
- `expired` and `stale` are derived read-model states for display/filtering and are not written merely because the console GET page was opened;
- the normal decision inbox includes only fresh pending `mutation` tickets for `mad4b-write` whose candidate/build binding exactly matches the current governed Staging build;
- replay, expiry, target/payload mismatch, candidate drift and class mismatch fail closed;
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

```json
{
  "contract": "mad4b.approval.v1",
  "site": "site-origin-or-install-id",
  "agent_public_id": "...",
  "server_id": "mad4b-write",
  "ability": "elementor/update-widget-settings",
  "provider": "elementor",
  "target": "...",
  "ticket_class": "mutation",
  "input": {}
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

Schema version is stored in option `mad4b_scp_schema_version` and current expected version is `5`.

Activation/boot rules:
1. `dbDelta()` creates/updates only MAD4B-prefixed tables.
2. Migration is idempotent.
3. Migration never auto-creates enabled NHI authority.
4. Existing global mutation enablement never implies NHI authority.
5. Missing/partial schema produces `governance_schema_unavailable` and governed mutation fails closed.
6. A successful deep physical-integrity verification writes the bounded schema-integrity token used by normal read/hot paths; mutation/approval authority boundaries still use the memoized physical guard and fail closed on physical drift.
7. Legacy option-based candidate bindings may be migrated into v5 ticket-row columns for compatibility, but new exact bindings are persisted on the approval row.
8. Audit head/legacy anchor is initialized only after schema readiness.
9. No legacy capability is widened during migration.

## Retention and evidence

- agent/subject/grant history is retained through disable/revoke-oriented lifecycle rather than destructive defaults;
- approval and mutation records retain operational evidence according to future retention policy while hashes/status remain authoritative;
- append-only audit is the primary local security evidence chain;
- retention must not silently destroy the sole evidence required for authorization/mutation reconstruction;
- external WORM/SIEM export may be added through the post-commit hook without changing local authority semantics.
