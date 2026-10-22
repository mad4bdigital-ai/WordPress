# Direct Authenticated MCP Profile — implementation contract (NOT ACTIVE)

Status: **design-only, not an installed feature**. This document deliberately
does not change any transport authorization, mutation permission, or release
policy. It records the separate implementation required by the site owner.
PR #468 governs optional operator warning presentation, not MCP authority.

## Required user experience

Site owners want **Direct** to be the default *for an authenticated enrolled
owner connection*, with **Governed** available from WordPress Settings or via
an existing protected remote setting operation. The ordinary session should
support immediate reading, post CRUD, media CRUD and taxonomy management,
according to the WordPress user's **actual capabilities**. A per-tool approval
ticket and MAD4B NHI grant should not be prerequisites to those **ordinary**
operations in Direct mode.

"Direct" does **not** mean unauthenticated Internet-wide control of the site;
that is not a safe default and is not part of this implementation contract.

## Profiles / requested behavior

| Mode | Default | Effective behavior |
| --- | --- | --- |
| `direct_authenticated` | New installations only | Authenticated WordPress user; ordinary content/media/taxonomy reads & writes use native ability permission callbacks and individual WordPress capability/ownership tests. No per-change MAD4B approval workflow. |
| `governed` | Operator selectable | Existing MAD4B grant, impact, approval, policy and execution claims, unchanged. |
| `read_only` | Operator selectable | Tools may list but all mutations denied, including indirect/nested writes. |
| `maintenance_lock` | Operator selectable | Deny new write entry; permit read-only incident diagnostics and verification. |

Saved existing installations must not silently migrate to a newly privileged
default. For upgrades, preserve existing Governed settings until the owner
explicitly opts into Direct and the runtime and client are re-certified. This
prevents unintentionally widening an already connected Production app.

## Explicit security floor (independent of optional governance)

- Authenticate the MCP HTTP request and verify it belongs to a real WP user.
  OAuth signature, issuer, subject/client binding, expiration, audience, CSRF
  state and redirect controls remain mandatory; no anonymous POST writes.
- Honor WP capabilities per ability, including edit/delete/publish permissions,
  post ownership, CPT-specific capabilities, media upload and taxonomies.
  A `manage_options` user is not implicitly allowed to edit arbitrary private
  data belonging to other tenants.
- Keep route identity, approved WordPress Ability callbacks, JSON schema
  validation, read/write classification and transport host/origin isolation.
- Do **not** expose plugin installation, arbitrary PHP/file/SQL execution,
  credential/secrets, site users/roles, authentication configuration, remote
  connector administration, breakglass or production deployment through
  ordinary Direct mode. They require an independent privileged capability
  path and explicit user action.
- Preserve an emergency admin-only write kill switch, bounded audit events,
  idempotency / uncertain-outcome handling, and failure diagnostics.
- Applying a new profile remotely is itself a privileged mutation. An
  untrusted or read-only client cannot grant itself Direct, modify someone
  else's owner binding, or override a server-side lock.
- "Governed" must restore the complete old behavior, not just rename the
  status. A profile switch must be atomic, revisioned, auditable, and verified.
- The absence, corruption or version mismatch of the new profile option must
  **not** grant authority when a runtime is only partially upgraded.

## Implementation plan — required source changes before feature can ship

1. Implement one authoritative `MCP_Access_Profile` service, separate from
   `Optional_Governance`, with `requested`, `effective`, `version`,
   `revision`, `profile_source`, `credential_scope` and `deny_reason`.
   The server must report Direct as effective **only after** the direct
   transport and capability enforcement paths are actually certified.
2. Extend the existing WordPress Options Control page with a profile switch
   and per-feature capability preview. Expose protected read-only Status,
   Plan and Verify, and an owner-authorized profile Apply. Never add an
   unauthenticated REST endpoint or secret-bearing status.
3. Introduce a dedicated, allowlisted native MCP direct server (or an
   equivalently isolated dispatch path) for trusted WordPress post/media/
   taxonomy Abilities. Do not bypass or loosen
   `MAD4B_SCP_Authorization::claim_mutation` for existing governed server
   operations; changing its semantics globally may widen unrelated
   execution and breakgrlass surfaces.
4. Authenticate and authorize at **both** transport and each Ability
   permission callback, including nested calls and projected tools.
5. Enforce exact tool/Ability identity, provider eligibility and limits
   before mapping a direct request. Native WordPress role/capability checks
   are the sole mutation authority for the allowlisted ordinary operations.
6. Keep remote profile switching on a separate privileged owner-only
   control plane, not on the Direct tool inventory. Verify the effective
   profile and current user binding after every switch.
7. Test authentication, WP capability variation, 403 vs 401, read/write
   visibility, stale OAuth sessions, cross-site and multisite isolation,
   replay/concurrent calls, nested calls, timeouts, rollback, profile
   transitions, upgrade from existing installs, and Production staging
   parity. Require real external ChatGPT initialize/tools/list/tools/call
   proof before marking the system ready.

## Existing code seams verified

- `class-mad4b-scp-servers.php`: REST MCP transport admission;
  `can_chatgpt_transport` is a read-capability gate and `can_write_transport`
  requires WordPress admin.
- `class-mad4b-scp-policy.php`: `can_read` checks actual WP capability;
  `mutation_gate_status` separately requires Site Profile / governed
  Production runtime readiness.
- `class-mad4b-scp-authorization.php`: `authorize_mutation_impl` requires
  governance schema, peer inventory, transport resolution, NHI agent,
  exact grant, resource constraints, optional ticket, budgets and policy;
  `wrap_execution_boundary` invokes the governed claim at execution.
- `class-mad4b-scp-execution-fence.php`: terminal mutation admission and
  nested execution provenance.
- `class-mad4b-scp-optional-governance.php` (PR #468): an independent
  optional **warning presentation** control, not an authorization source.

### Why no global "disable all governance" bit is safe or sufficient

Blindly changing a flag on the optional warning page cannot make an MCP
connection work: client OAuth, the MCP registration/transport runtime, and
the WordPress user permission checks still run. Conversely, bypassing the
global mutation authorization wrapper would remove defenses from admin,
developer, plugin update, Production and recovery operations. A dedicated
Direct authenticated capability plane prevents those two errors.

## Live acceptance checklist

Record on each site independently: installed package SHA, package/source
identity, OAuth discovery/authorize/token, authorized MCP initialize,
tools/list, authorized read tool, authorized **non-destructive** write test
against an explicitly designated sandbox resource, readback, expected
denials for unprivileged and unauthenticated users, and profile revert.

As of this design, both ETG and ARE MCP connector `site_info` and
`session-safe-diagnostics` returned `The tool failed internally`. That
failure is not diagnosed as an authorization policy alone, and this
documentation-only PR does not restore those live connections.

## Completion gate

Do not change the default to effective Direct, claim a live capability,
install a package or merge into `master` merely because this document
exists. Implementation and CI remain open work.
