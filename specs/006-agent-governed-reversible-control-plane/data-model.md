# Data Model — Agent-Governed Reversible Control Plane

Status: Normative schema design for implementation
Storage scope: site-local WordPress database tables
Encoding: UTF-8 / JSON text only where structured extension fields are required
Secret policy: no plaintext bearer/OAuth credential persistence

## Table 1 — `{prefix}mad4b_scp_agents`

Purpose: stable NHI identity records.

Columns:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `public_id CHAR(36) NOT NULL UNIQUE` — opaque UUID-like external identifier; immutable.
- `slug VARCHAR(191) NOT NULL UNIQUE` — operator-facing stable slug.
- `label VARCHAR(191) NOT NULL`
- `status VARCHAR(20) NOT NULL` — `disabled|enabled` initially.
- `wp_user_id BIGINT UNSIGNED NULL` — optional human/service principal association; does not replace NHI grants.
- `environment VARCHAR(32) NOT NULL DEFAULT 'unknown'` — policy hint; expected `production|staging|development|local|unknown`.
- `revision BIGINT UNSIGNED NOT NULL DEFAULT 1` — optimistic admin-update guard.
- `created_by BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `created_at DATETIME NOT NULL`
- `updated_at DATETIME NOT NULL`

Indexes:
- unique `public_id`
- unique `slug`
- index `(status)`
- index `(wp_user_id)`

Invariants:
- status values are validated in PHP rather than trusting DB enum semantics;
- disabling an agent does not delete history/grants;
- `public_id` never changes;
- delete is not part of initial product contract; use disable for forensic continuity.

## Table 2 — `{prefix}mad4b_scp_agent_subjects`

Purpose: bind authenticated upstream transport subjects to one NHI without storing secrets.

Columns:
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `agent_id BIGINT UNSIGNED NOT NULL`
- `subject_type VARCHAR(64) NOT NULL` — e.g. `oauth_client`, `oauth_subject`, `credential_fingerprint`, `certified_bridge`.
- `subject_fingerprint CHAR(64) NOT NULL` — lowercase SHA-256 hex of canonical non-secret identifier/evidence.
- `label VARCHAR(191) NOT NULL DEFAULT ''`
- `status VARCHAR(20) NOT NULL DEFAULT 'enabled'`
- `created_at DATETIME NOT NULL`
- `updated_at DATETIME NOT NULL`

Indexes:
- unique `(subject_type, subject_fingerprint)` to prevent ambiguous active bindings;
- index `(agent_id, status)`.

Invariants:
- no raw token/secret accepted by persistence API;
- fingerprints are normalized 64-char lowercase hex;
- resolving more than one record is treated as corruption/blocker even if DB uniqueness should prevent it;
- deleting a subject binding requires admin capability and audit; implementation may initially support disable-only.

## Table 3 — `{prefix}mad4b_scp_agent_grants`

Purpose: normalized exact ability/server grants.

Columns:
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `agent_id BIGINT UNSIGNED NOT NULL`
- `effect VARCHAR(10) NOT NULL DEFAULT 'allow'` — `allow|deny`; deny precedence.
- `server_id VARCHAR(64) NOT NULL`
- `ability_name VARCHAR(191) NOT NULL`
- `provider VARCHAR(64) NOT NULL DEFAULT 'core'`
- `resource_schema_version VARCHAR(32) NOT NULL DEFAULT 'v1'`
- `resource_constraints LONGTEXT NULL` — bounded canonical JSON object; empty means no additional narrowing.
- `environment VARCHAR(32) NOT NULL DEFAULT 'all'`
- `created_by BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `created_at DATETIME NOT NULL`
- `updated_at DATETIME NOT NULL`

Indexes:
- unique `(agent_id, effect, server_id, ability_name, provider, environment)`
- index `(agent_id, ability_name)`
- index `(server_id, ability_name)`.

Invariants:
- first implementation rejects ability names containing wildcard metacharacters;
- `*` and prefix wildcards are never stored as Production grants;
- `deny` overrides `allow`;
- server must match the server in which the ability is actually mounted;
- constraints JSON size is bounded (initial target <= 16 KiB) and maximum depth bounded;
- unknown constraints schema or unknown constraint key for a writer that requires constraints => deny.

## Table 4 — `{prefix}mad4b_scp_approval_tickets`

Purpose: single-use short-lived authorization artifact for one high-impact operation.

Columns:
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `ticket_id CHAR(36) NOT NULL UNIQUE`
- `ticket_class VARCHAR(32) NOT NULL` — `mutation|breakglass|recovery`.
- `agent_id BIGINT UNSIGNED NOT NULL`
- `server_id VARCHAR(64) NOT NULL`
- `ability_name VARCHAR(191) NOT NULL`
- `provider VARCHAR(64) NOT NULL DEFAULT 'core'`
- `target_fingerprint VARCHAR(191) NOT NULL DEFAULT ''`
- `payload_sha256 CHAR(64) NOT NULL`
- `status VARCHAR(20) NOT NULL DEFAULT 'pending'` — `pending|approved|used|expired|revoked`.
- `reason TEXT NOT NULL`
- `approved_by BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `approved_at DATETIME NULL`
- `expires_at DATETIME NOT NULL`
- `used_at DATETIME NULL`
- `created_at DATETIME NOT NULL`

Indexes:
- unique `ticket_id`
- index `(agent_id, status, expires_at)`
- index `(payload_sha256, status)`.

Invariants:
- `payload_sha256` is hash of canonical approval envelope, not raw request text;
- consuming a ticket requires atomic state transition `approved -> used` and matching unexpired hash/agent/ability/class;
- normal mutation ticket cannot authorize Breakglass;
- ticket use cannot bypass provider drift, WordPress capability, stale-state or budget checks;
- approval TTL is bounded by policy; initial default target 10 minutes, max target 60 minutes unless explicitly changed by filter with audit.

## Table 5 — `{prefix}mad4b_scp_mutations`

Purpose: durable mutation/verification/undo envelope.

Columns:
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `mutation_id CHAR(36) NOT NULL UNIQUE`
- `request_id VARCHAR(64) NOT NULL`
- `parent_mutation_id CHAR(36) NULL` — used for undo/recovery lineage.
- `agent_id BIGINT UNSIGNED NOT NULL`
- `subject_type VARCHAR(64) NOT NULL DEFAULT ''`
- `subject_fingerprint CHAR(64) NOT NULL DEFAULT ''`
- `wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `server_id VARCHAR(64) NOT NULL`
- `ability_name VARCHAR(191) NOT NULL`
- `provider VARCHAR(64) NOT NULL DEFAULT 'core'`
- `provider_version VARCHAR(64) NOT NULL DEFAULT ''`
- `target_type VARCHAR(64) NOT NULL DEFAULT ''`
- `target_id VARCHAR(191) NOT NULL DEFAULT ''`
- `approval_ticket_id CHAR(36) NULL`
- `impact VARCHAR(20) NOT NULL` — `low|high|exceptional`.
- `status VARCHAR(32) NOT NULL` — `planned|executing|verification_failed|verified|rollback_failed|undone|failed|non_reversible`.
- `reversible TINYINT(1) NOT NULL DEFAULT 0`
- `before_sha256 CHAR(64) NOT NULL DEFAULT ''`
- `after_sha256 CHAR(64) NOT NULL DEFAULT ''`
- `rollback_payload LONGTEXT NULL` — bounded JSON from certified snapshot contract.
- `rollback_payload_sha256 CHAR(64) NOT NULL DEFAULT ''`
- `undo_expires_at DATETIME NULL`
- `verification_code VARCHAR(64) NOT NULL DEFAULT ''`
- `error_code VARCHAR(64) NOT NULL DEFAULT ''`
- `created_at DATETIME NOT NULL`
- `updated_at DATETIME NOT NULL`

Indexes:
- unique `mutation_id`
- index `(agent_id, created_at)`
- index `(ability_name, created_at)`
- index `(status, created_at)`
- index `(parent_mutation_id)`
- index `(request_id)`.

Invariants:
- raw secrets are not stored in rollback payload;
- rollback payload max initial target 256 KiB and bounded depth; larger resources are non-reversible until external snapshot contract exists;
- `verified` requires read-after-write verification;
- undo requires `reversible=1`, unexpired undo and current state hash == recorded `after_sha256` or certified equivalent fingerprint;
- undo creates a new record linked with `parent_mutation_id`; original history is never rewritten.

## Table 6 — `{prefix}mad4b_scp_agent_budgets`

Purpose: policy configuration for blast-radius controls.

Columns:
- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `agent_id BIGINT UNSIGNED NOT NULL`
- `budget_type VARCHAR(32) NOT NULL` — `requests|mutations|affected_objects|external_actions`.
- `window_seconds INT UNSIGNED NOT NULL`
- `max_count INT UNSIGNED NOT NULL`
- `enabled TINYINT(1) NOT NULL DEFAULT 1`
- `updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `updated_at DATETIME NOT NULL`

Unique `(agent_id, budget_type)`.

Runtime counters are not stored by endlessly appending rows to this table. Initial implementation may use atomic transients/options for counters while preserving config here; production-grade high-concurrency counters may move to a dedicated bucket table.

## Transport subject context object

Not persisted as a secret. Normalized runtime structure:

```json
{
  "authenticated": true,
  "subject_type": "oauth_client",
  "subject_identifier": "non-secret canonical identifier if safe",
  "subject_fingerprint": "64hex",
  "token_scopes": ["ability:mad4b/content-update-post"],
  "auth_method": "mcp-adapter",
  "wp_user_id": 123,
  "request_id": "..."
}
```

Rules:
- adapter bridge may omit `subject_identifier` and supply only fingerprint;
- scopes are normalized strings, exact-match first implementation;
- `authenticated=false` cannot resolve an NHI for mutation;
- context must not contain Authorization header/token.

## Canonical approval envelope

```json
{
  "contract": "mad4b.approval.v1",
  "site": "site-origin-or-install-id",
  "agent_public_id": "...",
  "server_id": "mad4b-admin",
  "ability": "mad4b/database-update",
  "provider": "core",
  "target": "...",
  "input": {}
}
```

Canonicalization requirements:
- recursive lexicographic object key sort;
- preserve array order;
- normalize booleans/null/numbers/UTF-8 strings;
- reject resources/objects/non-finite numbers;
- reject depth > configured bound;
- reject canonical JSON > configured bytes;
- hash SHA-256 of canonical UTF-8 JSON bytes.

## Migration strategy

Schema version stored as `mad4b_scp_schema_version` option.

Initial migration target: `2` for NHI/approval/mutation foundation.

Activation/boot migration rules:
1. `dbDelta()` creates/updates only MAD4B-prefixed tables.
2. Migration never auto-creates enabled agents or grants.
3. Existing v0.3 sites remain read-capable.
4. Existing global mutation enablement does not imply NHI authority; mutation becomes NHI-gated once feature code lands.
5. Migration failure sets runtime blocker `governance_schema_unavailable` and mutation fails closed.
6. No legacy capability is widened during migration.

## Retention

- agents/grants/subjects: retain unless explicitly administratively removed/disabled according to future policy;
- approval tickets: retain minimum operational window for audit, then may redact detail while preserving hashes/status;
- mutation records: longer retention than approvals; exact policy configurable later;
- audit chain: current option model retained in first implementation, future append-only table must reference mutation IDs.
