# Implementation Plan — Agent-Governed Reversible Control Plane

## Architecture summary

The feature adds a governance layer **above** existing WordPress capability/provider checks and **below** provider side effects. It does not replace MCP Adapter transport or WordPress Abilities execution.

```text
MCP client
  ↓
official MCP Adapter authentication/session/transport
  ↓
authenticated subject context bridge
  ↓
MAD4B NHI resolver
  ↓
exact agent grant + token scope + server membership
  ↓
WordPress capability + existing MAD4B global mutation gate
  ↓
provider certification/runtime integrity
  ↓
resource policy + rate/budget
  ↓
approval ticket when impact requires it
  ↓
precondition / snapshot
  ↓
provider callback
  ↓
read-after-write verification
  ↓
mutation record + audit + optional undo token
```

## New classes

### `MAD4B_SCP_Schema`

Responsibilities:
- schema version constant;
- `install_or_upgrade()` using `dbDelta()`;
- table-name helpers;
- migration readiness/self-test;
- no creation of default enabled authority.

### `MAD4B_SCP_Agent_Registry`

Responsibilities:
- create/read/update/disable agent;
- bind/disable authenticated subject fingerprints;
- grant/revoke exact ability grants;
- resolve enabled agent from normalized subject context;
- return structured inventory for admin/runtime diagnostics.

This class is persistence/validation only. It does not independently authorize provider actions.

### `MAD4B_SCP_Identity_Context`

Responsibilities:
- construct normalized request identity context;
- receive upstream evidence through `mad4b_scp_authenticated_subject_context` filter;
- hash safe canonical subject identifiers where required;
- refuse token/header secret material;
- provide request correlation ID;
- optionally identify whether current call is MCP-originated when bridge evidence can prove it.

Initial compatibility strategy:
- absent subject context does not break read-only WP-CLI/runtime diagnostics;
- mutation with global switch ON requires NHI identity for MCP/external execution;
- explicit internal/local execution exception is not enabled until a safe origin signal is defined and tested.

### `MAD4B_SCP_Authorization`

Responsibilities:
- central effective-permission evaluation;
- exact ability + server + provider grant lookup;
- deny precedence;
- token-scope intersection;
- resource constraint evaluation;
- rate/budget preflight;
- approval requirement classification;
- structured decision object and audit emission.

Public API sketch:

```php
MAD4B_SCP_Authorization::authorize( array $context ); // true-like decision or WP_Error
MAD4B_SCP_Authorization::effective_access( $agent_public_id );
MAD4B_SCP_Authorization::impact_for( $ability_name, array $metadata = array() );
```

### `MAD4B_SCP_Approval_Tickets`

Responsibilities:
- canonical payload generation/hashing;
- create pending ticket;
- admin approval/revoke;
- validate/consume approved ticket atomically;
- class separation: normal mutation vs breakglass/recovery;
- TTL enforcement/replay rejection.

### `MAD4B_SCP_Mutation_Manager`

Responsibilities:
- create mutation record before side effect;
- snapshot contract invocation;
- provider execution envelope;
- readback verification;
- result classification;
- undo eligibility and execution;
- lineage for undo/recovery.

The manager does not directly know Elementor/JetEngine internals. Adapters implement narrow reversible-operation hooks.

### `MAD4B_SCP_Mutation_Contract` (interface-like PHP 7.4 compatible convention)

Because existing adapters are concrete classes and PHP 7.4 is supported, the first implementation may use method-presence conventions rather than forcing all adapters to implement an interface immediately.

Optional adapter methods:

```php
mutation_target( $ability, $input )
mutation_snapshot( $ability, $input )
mutation_state_fingerprint( $ability, $input )
mutation_restore( $ability, $rollback_payload, $expected_after )
mutation_verify( $ability, $input, $execution_result )
```

Unknown/unsupported method means `non_reversible` rather than generic storage writes.

### `MAD4B_SCP_Budgets`

Responsibilities:
- policy lookup;
- atomic-ish per-agent counters;
- request/mutation/object budget preflight;
- denial with reset/window metadata safe for authorized operator diagnostics.

### `MAD4B_SCP_MCP_Peer_Inventory`

Responsibilities:
- inspect registered MCP server/known plugin surface evidence available at runtime;
- classify peers through explicit policy filter/catalog;
- report unknown write-capable peers as blockers;
- never auto-disable peer plugins.

## Existing classes to modify

### Main plugin bootstrap

Add requires in dependency order:
1. schema
2. identity context
3. agent registry
4. budgets
5. approvals
6. authorization
7. mutations
8. MCP peer inventory
9. existing policy/audit/provider/abilities/adapters/servers/plugin.

Activation runs schema upgrade before storing final plugin version.

### `MAD4B_SCP_Policy`

Keep current global/static controls. Do not move NHI storage into Policy. Add only policy helpers/filter defaults such as:
- require NHI for mutation;
- approval impact override;
- Production wildcard prohibition (hard default, no silent widening);
- approval TTL bounds;
- mutation rollback max bytes/depth.

### Core abilities

Mutation permission wrapper changes from:

`WP capability → global mutation`

to:

`WP capability → global mutation → MAD4B authorization/NHI/grant/scope/budget/approval-precondition`.

Read abilities remain compatible initially but can expose an `effective access` admin/read ability.

New core abilities in staged rollout:
- `mad4b/agent-list` (admin read)
- `mad4b/agent-effective-access` (admin read)
- `mad4b/approval-plan` (admin/high-impact planning; no mutation)
- `mad4b/approval-status` (admin read)
- `mad4b/mutation-get` (admin read)
- `mad4b/mutation-undo` (admin/recovery mutation; approval depending impact)
- `mad4b/runtime-authority-status` (read/admin diagnostic)

Agent/grant creation abilities are NOT exposed to generic autonomous agents initially. Configuration stays human-admin via internal/admin interfaces until admin UX/CSRF-protected endpoints are implemented.

### Adapter base

Extend central mutation permission wrapper to pass:
- ability name;
- provider slug;
- server/surface;
- input;
- readonly/destructive metadata.

No adapter may reimplement independent NHI logic.

### Runtime self-test

Add governance block:

```json
{
  "governance": {
    "schema_ready": true,
    "nhi_mutation_required": true,
    "enabled_agents": 0,
    "wildcard_grants": 0,
    "duplicate_subjects": 0,
    "approval_storage_ready": true,
    "mutation_storage_ready": true,
    "write_side_channel_blockers": []
  }
}
```

When global mutation is OFF, zero enabled agents is informational. When mutation is ON and no valid mutation NHI exists, self-test is blocked.

## Authorization evaluation order

Order matters to minimize leakage and side effects:

1. Validate WordPress ability input schema.
2. Existing ability-level WordPress permission callback.
3. Global MAD4B mutation switch for writers.
4. Resolve identity context.
5. Resolve enabled NHI.
6. Confirm ability is actually mounted in requested server/surface.
7. Evaluate deny then allow exact grants.
8. Intersect token scopes if supplied/required.
9. Provider certification/runtime integrity.
10. Resource constraints.
11. Rate/mutation budget preflight.
12. Determine impact/approval requirement.
13. Validate approval ticket if execution requires one.
14. Execute provider callback through mutation manager.

Detailed denial reason is audit-only by default because WordPress Ability deliberately masks permission details in normal execution.

## Approval flow

```text
read/discover current state
  ↓
plan exact mutation
  ↓
canonical approval envelope
  ↓
SHA-256
  ↓
pending ticket
  ↓
human approve (short TTL)
  ↓
agent executes exact payload
  ↓
atomic consume ticket
```

No approval API accepts a caller-supplied payload hash without independently recalculating it from normalized operation data.

## Mutation manager flow

### Reversible operation

1. Authorization passes.
2. Record `planned`.
3. Adapter target + before fingerprint.
4. Capture bounded rollback payload.
5. Mark `executing`.
6. Execute existing provider mutation method.
7. Readback fingerprint/verify.
8. If matched: `verified`, after hash, undo expiry.
9. If mismatched: `verification_failed`; safe certified rollback may be attempted.
10. Audit final state.

### Non-reversible operation

The operation is marked non-reversible before side effects. High-impact non-reversible operations always require approval. The result does not pretend to return undo capability.

### Undo

1. Load verified reversible mutation.
2. Reauthorize current caller/NHI.
3. Check expiry.
4. Read current fingerprint.
5. Require current == recorded after fingerprint.
6. Execute provider restore contract.
7. Verify restored fingerprint == before fingerprint.
8. Record child mutation `undone` and audit.

## Initial impact classification

- `read`: all readonly abilities; no approval.
- `low`: narrowly bounded content/meta/media mutation with optimistic guard and reversible provider contract; policy may allow without human ticket for trusted NHI.
- `high`: publish/status changes, plugin lifecycle, DB structured writes, Elementor legacy writes, Flow execution, bulk writes, non-reversible writes; approval required by default.
- `exceptional`: Breakglass/raw SQL/recovery; separate approval class + existing breakglass gates.

No provider may self-label an operation lower than central hard minimum without explicit reviewed policy.

## Phased implementation

### Phase A — Schema + identity foundation

- schema migration;
- agent/subject/grant stores;
- context resolver;
- exact grants;
- authorization dry-run/effective access;
- no change to provider callbacks yet except fail-closed NHI mutation gate behind global mutation.

### Phase B — Approval foundation

- canonicalizer;
- approval tables/services;
- impact registry;
- high-impact precondition gate;
- replay/expiry tests.

### Phase C — Mutation envelope + undo

- mutation records;
- generic manager;
- implement reversible contract for one low-risk core/content operation first;
- read-after-write verification;
- drift-safe undo tests;
- then extend adapters deliberately.

### Phase D — Budgets + side-channel governance

- per-NHI budgets;
- peer inventory/self-test blocker;
- effective access diagnostics.

### Phase E — Admin UX and target staging

- Agents/Grants/Approvals/Mutations admin pages;
- CSRF/capability protections;
- staging subject bridge certification;
- benign mutation/undo acceptance.

## Rollout safety

- No migration auto-enables NHI or grants.
- Existing installation remains read-safe.
- If global mutation constant is OFF, behavior remains deny.
- After code lands, global mutation ON without NHI must fail closed rather than preserve old broad mutation behavior.
- Breakglass remains independently gated and does not receive automatic normal NHI grants.
- Plugin version bump occurs only after feature code/tests are coherent; docs must not advertise Production readiness before target acceptance.

## CI additions

New static contract test: `tests/agent-governance-contract.py`.

New runtime smoke assertions:
- schema tables exist;
- no default agents/grants created;
- global mutation remains OFF;
- forced global-mutation test context without NHI is denied before side effects;
- enabled agent with exact grant + test subject may pass NHI layer but still obey provider/global/WordPress gates;
- unrelated ability denied;
- wildcard grant creation rejected;
- duplicate subject rejected;
- approval wrong payload/expired/replay denied;
- reversible sample mutation verifies and undo works;
- undo after deliberate external drift denied.

## Packaging

Spec files and development tests remain excluded from install ZIP only if package policy intentionally excludes development documentation. Runtime classes/migrations and any JSON policy baseline required at runtime must ship. Exact-head package manifest continues to bind output to PR head SHA.
