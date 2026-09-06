# Ability Contracts — Governance Layer

Status: Normative interface contract

## Contract conventions

All abilities:
- remain `meta.public=false`, `show_in_rest=false`, `meta.mcp.public=false`;
- use explicit MCP type `tool`;
- preserve WordPress Abilities input/output validation;
- never return bearer secrets or raw authorization headers;
- return opaque public IDs/fingerprints only;
- all mutation abilities are destructive=true for MCP annotations;
- error detail may be masked by WordPress permission behavior; audit stores specific internal denial code.

## `mad4b/runtime-authority-status`

Surface: `mad4b-read` or `mad4b-admin` depending detail level.
Readonly: true.
Permission: `manage_options` for full identity details; sanitized aggregate may later be read-surface.

Output:
```json
{
  "schema_ready": true,
  "mutation_global_enabled": false,
  "nhi_mutation_required": true,
  "enabled_agents": 0,
  "enabled_subject_bindings": 0,
  "exact_grants": 0,
  "wildcard_grants": 0,
  "approval_storage_ready": true,
  "mutation_storage_ready": true,
  "side_channel_blockers": [],
  "status": "ready_read_only"
}
```

Rules:
- never reports secret identifiers;
- subject fingerprints may be truncated in normal output;
- if mutation global enabled but no valid enabled mutation NHI/grant exists, status is blocked.

## `mad4b/agent-list`

Surface: `mad4b-admin`.
Readonly: true.
Permission: `manage_options`.

Input:
- optional `status: enabled|disabled|all`;
- `limit` 1..200.

Output per agent:
- public_id, slug, label, status, wp_user_id, environment, revision;
- counts for subjects/grants/budgets;
- no raw subject identifier/credential.

## `mad4b/agent-effective-access`

Surface: `mad4b-admin`.
Readonly: true.
Permission: `manage_options`.

Input:
```json
{
  "agent_public_id": "uuid",
  "token_scopes": ["optional simulated exact scopes"],
  "server_id": "optional"
}
```

Output:
```json
{
  "agent": "...",
  "effective": [
    {
      "server_id": "mad4b-content",
      "ability": "mad4b/content-update-post",
      "provider": "core",
      "grant": "allow",
      "scope": "allowed",
      "provider_runtime": "n/a|certified|blocked",
      "impact": "low|high|exceptional",
      "approval_required": true,
      "resource_constraints": {}
    }
  ],
  "denied_count": 0
}
```

Simulation never consumes budgets or approvals and cannot execute providers.

## Administrative agent-management contract

Agent/grant creation is **not initially exposed to autonomous MCP clients**. The service methods support future WP Admin forms/REST only after CSRF and capability tests exist.

Required internal service operations:
- create_agent
- update_agent(expected_revision)
- disable_agent
- bind_subject
- disable_subject
- grant_exact_ability
- revoke_grant
- set_budget

Hard rules:
- wildcard ability names rejected;
- subject input accepted only as fingerprint or explicitly non-secret canonical identifier converted immediately to fingerprint;
- agent cannot be enabled by migration automatically;
- grants to `mad4b-breakglass` require explicit exceptional configuration path, not generic grant form default.

## `mad4b/approval-plan`

Surface: `mad4b-admin`.
Readonly with respect to provider resources: true, but it creates a local pending approval record; annotate destructive=false because no target mutation occurs.
Permission: `manage_options` for first implementation.

Input:
```json
{
  "agent_public_id": "...",
  "server_id": "mad4b-admin",
  "ability": "mad4b/database-update",
  "provider": "core",
  "target_fingerprint": "...",
  "input": {},
  "ticket_class": "mutation",
  "reason": "..."
}
```

Behavior:
1. confirm ability exists and is a mutation;
2. confirm requested server maps the ability;
3. confirm agent enabled and exact grant exists;
4. canonicalize operation;
5. calculate payload SHA;
6. create pending ticket with bounded TTL;
7. return plan/ticket ID and hash, never approval automatically.

Output includes:
- ticket_id;
- status pending;
- payload_sha256;
- expires_at;
- impact;
- provider certification snapshot summary;
- exact preconditions caller must preserve.

## Approval administrative service

First implementation may expose approval through WP Admin action rather than MCP ability.

Approve requirements:
- current_user_can('manage_options');
- nonce;
- ticket pending/unexpired;
- optional re-read of current target fingerprint if policy requires approval-time freshness;
- reason/approver stored;
- audit event.

Revoke requirements: same admin/nonce, no use after revoked.

## Mutation authorization input contract

Every core/adapter writer passes a structured context to `MAD4B_SCP_Authorization`:

```json
{
  "ability": "...",
  "server_id": "...",
  "provider": "core|elementor|jetengine|...",
  "readonly": false,
  "destructive": true,
  "impact": "low|high|exceptional",
  "input": {},
  "target": {
    "type": "post|file|table|plugin|flow|product|...",
    "id": "...",
    "fingerprint": "..."
  },
  "approval_ticket_id": "optional"
}
```

Adapters MUST NOT decide NHI grants themselves.

## `mad4b/mutation-get`

Surface: `mad4b-admin`.
Readonly: true.
Permission: `manage_options` or policy-defined agent access to own mutation records in future.

Input: mutation_id.
Output:
- identity summary;
- ability/provider/target;
- status;
- before/after hashes;
- reversible flag;
- undo expiry;
- verification code;
- approval ticket reference;
- error code;
- no rollback payload by default.

## `mad4b/mutation-undo`

Surface: `mad4b-admin`.
Readonly: false.
Destructive: true.
Impact: high by default.
Permission sequence:
1. WordPress admin/recovery capability;
2. global mutation enabled;
3. NHI/exact grant if MCP-originated;
4. approval if policy requires;
5. original mutation reversible/verified/unexpired;
6. current-state drift check;
7. provider restore contract certified.

Input:
```json
{
  "mutation_id": "...",
  "approval_ticket_id": "...",
  "reason": "..."
}
```

Errors:
- `mad4b_mutation_missing`
- `mad4b_undo_not_reversible`
- `mad4b_undo_expired`
- `mad4b_undo_state_drift`
- `mad4b_undo_provider_unavailable`
- `mad4b_undo_verification_failed`

Output:
```json
{
  "status": "undone",
  "original_mutation_id": "...",
  "recovery_mutation_id": "...",
  "restored_sha256": "...",
  "verified": true
}
```

## Impact hard minimums

The central policy MUST classify at least these as `high` or stronger regardless of adapter suggestion:
- plugin activate/deactivate;
- structured DB update;
- Elementor legacy direct document mutation;
- Bit Flow execution;
- bulk content mutation above one configured object;
- publish/private/status transition with public visibility impact;
- non-reversible operation;
- cache purge-all if configured as potentially disruptive.

`exceptional`:
- raw SQL Breakglass;
- future role/password/security-session mutations;
- future host/system actions (which remain outside this plugin).

## Scope syntax v1

First implementation accepts exact scope strings only:
- `ability:<exact-ability-name>`
- optional future `server:<exact-server-id>` is not sufficient alone for mutation; ability scope remains required when token scopes are enforced.

No `*`, regex, glob or prefix scopes in Production contract v1.

## Request identity hook

Filter:
```php
apply_filters( 'mad4b_scp_authenticated_subject_context', $context );
```

Expected returned array may contain:
- authenticated bool;
- subject_type;
- subject_identifier OR subject_fingerprint;
- token_scopes array;
- auth_method;
- wp_user_id;
- request_id;
- origin evidence.

Security rules:
- arrays containing keys matching authorization/token/secret/password patterns are rejected or redacted;
- if both identifier and fingerprint supplied, implementation verifies consistency when possible;
- filter output is untrusted until validated;
- multiple filters may not create multiple identities: final normalized context is one subject or failure.

## Provider reversible contract

Adapters may expose methods through a central registry/manager. Snapshot structure:

```json
{
  "contract": "mad4b.rollback.v1",
  "provider": "...",
  "target": {"type":"...","id":"..."},
  "before_sha256": "...",
  "payload": {},
  "bytes": 1234
}
```

Restore MUST use provider-aware APIs and revalidate authorization. Generic raw `update_option`, `update_post_meta` or SQL cannot be used as an automatic universal rollback mechanism unless that exact provider/resource contract is explicitly certified.
