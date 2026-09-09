# MAD4B WordPress Plugin + Skills

This package wraps the existing MAD4B WordPress MCP App with reusable workflow Skills and an exact-origin governed Staging write plane. Live WordPress data, OAuth authentication, NHI authorization, approvals, audit and tool execution stay in the MAD4B MCP control plane.

## Current safety boundary

- Portable Plugin capability: `Read + Write`.
- The existing ChatGPT transport remains `mad4b-chatgpt`.
- On **`staging.egypttourgates.com` only**, supported non-readonly MAD4B/core and certified-adapter actions are exposed through the same Plugin and rebound to the dedicated `mad4b-write` authority server.
- Production never receives this automatic write authority.
- Breakglass and `mad4b/database-raw-query` are never part of the normal write surface.
- OAuth remains identity/authentication only; a `mad4b:read` bearer is not write authority.
- Actual remote mutations require a dedicated enabled NHI, an exact `mad4b-write` grant, provider/runtime policy, budgets, audit and a short-lived one-time exact approval ticket.
- `mad4b/approval-plan` is itself governed by NHI + exact grant + budget. It is the bounded bootstrap exception that may create a **pending** mutation ticket only; it never auto-approves or executes the target operation.
- Runtime-authored custom Skill creation remains WordPress-administrator UI only.
- ChatGPT Skill management remains read-only: `skills-list`, `skill-get`, `skills-export-status`, `skills-runtime-certification`.
- No Skill create/update/delete/write MCP tool is exposed.

## Zero-touch Staging lifecycle

On the exact governed Staging origin the Control Plane performs the normal setup without administrator intervention. It **automatically enables the local Skill editor**, binds the governed Staging OpenAI App mapping and configures the governed mutation gate unless an explicit operator disable is already present.

```text
Plugin boots
   ↓
Exact environment + staging.egypttourgates.com origin check
   ↓
Skill editor + Staging App mapping
   ↓
Governance schema + append-only audit
   ↓
Base site/connection/workflow Skills
   ↓
Provider discovery + MAD4B adapter registry
   ↓
Provider Skill reconciliation
   ↓
Dedicated Staging write NHI + exact mad4b-write grants
   ↓
Deterministic Skill snapshot identity
   ↓
Skill runtime certification
   ↓
Write runtime certification
```

No `wp-config.php` edit is required on Staging for the normal governed path. No administrator form submission is required for the automatic bootstrap.

Production is never auto-enabled for Staging Skill authoring, Staging App binding or the governed mutation gate. Supporting Skill `scripts/` authoring also remains separately gated.

## Governed write inventory

`MAD4B_SCP_Servers::write_tools()` projects every MAD4B/core or certified MAD4B adapter ability that is registered on the `content` or `admin` surfaces with explicit `readonly=false` metadata. It does not infer write capability from an ability name and it does not automatically trust arbitrary third-party abilities outside the certified MAD4B adapter registry.

Core examples include:

- `mad4b/content-update-post`
- `mad4b/plugin-activate`
- `mad4b/plugin-deactivate`
- `mad4b/filesystem-write`
- `mad4b/filesystem-patch`
- `mad4b/database-update`
- `mad4b/mutation-undo`
- `mad4b/approval-plan`

Provider-specific update/write/mutation actions are added from the live certified adapter registry. Their own capability, provider certification, stale-state, target and policy gates remain active.

`mad4b/database-raw-query` remains isolated under `mad4b-breakglass` and is excluded from `mad4b-write` and from the ChatGPT Plugin.

## Approval bootstrap

Every actual remote write requires an exact one-time approval ticket. The first ticket needs a safe bootstrap path, so `mad4b/approval-plan` has a deliberately narrower exception:

```text
verified OAuth identity
       ↓
dedicated Staging NHI
       ↓
exact grant: mad4b-write + mad4b/approval-plan
       ↓
budget + audit
       ↓
validate same Staging agent + mad4b-write target
       ↓
validate target is certified write inventory
       ↓
mutation ticket class only
       ↓
create PENDING ticket
       ↓
human approval remains separate
```

The remote planner cannot target itself, another agent, Breakglass/raw SQL, recovery authority, an unmounted ability or a provider that does not match the certified mount.

## WPML / WordPress REST compatibility

The Control Plane does not need to disable the WordPress REST API or globally intercept unrelated REST authentication.

The read-only `mad4b/rest-compatibility-status` evidence surface:

- reads the live `rest_enabled` hook inventory;
- reads the live `rest_authentication_errors` hook inventory;
- reports whether a callback belongs to the MAD4B Control Plane without exposing absolute server filesystem paths;
- exercises `/wpml/v1/rest/status` through the WordPress REST dispatcher with `test_get_parameter=1` and a `cachebuster`;
- requires WPML's `status=valid` and `get_parameters=valid` when WPML is active and its route is registered;
- fails closed if WPML is active but its health route is missing.

The internal probe proves WordPress/PHP/plugin behavior. CDN, WAF, Apache/Nginx and external reverse-proxy behavior remain a separate live HTTP acceptance boundary and must also be checked on the real Staging URL.

## Automatic runtime certifications

### Skill runtime

`mad4b/skills-runtime-certification` verifies the local Skill registry, seed pack, provider reconciliation, exact Staging App mapping, deterministic snapshot identity and absence of Skill mutation abilities.

### Write runtime

`mad4b/write-runtime-certification` verifies locally provable write facts including:

- exact Staging environment and origin;
- governed mutation gate;
- OAuth identity boundary;
- dedicated Staging NHI and exact grants;
- complete non-readonly write mounting on `mad4b-write` and the same ChatGPT Plugin transport;
- approval-plan bootstrap governance;
- one-time approval requirement for actual writes;
- Breakglass exclusion;
- no wildcard grants;
- REST global-hook isolation and WPML internal query-parameter probe;
- peer-governance readiness;
- append-only evidence digest.

Both certifications remain local-runtime evidence. WordPress never claims that ChatGPT/Codex refreshed the external Plugin snapshot or successfully executed a write.

## Provider-aware Skill packs

The provider catalog is stored at:

```text
wp-content/plugins/mad4b-site-control-plane/config/skill-provider-catalog.json
```

Automatic families include Elementor, JetEngine, JetSmartFilters, WooCommerce, Polylang, Rank Math, LiteSpeed Cache, media optimization providers, ETG Dynamic Filter SEO Bridge, BitFlows, Fluent Forms and WPML.

Runtime Skills live outside third-party plugin directories:

```text
wp-content/mad4b-skills/
├── site/_site/<skill>/SKILL.md
├── connection/<target>/<skill>/SKILL.md
├── provider/<provider>/<skill>/SKILL.md
├── adapter/<adapter>/<skill>/SKILL.md
└── workflow/<workflow-family>/<skill>/SKILL.md
```

Existing administrator-authored Skills always win. MAD4B-managed provider metadata can be enabled/disabled according to provider runtime state, but user-owned Skill contents are not overwritten or deleted.

## Deterministic snapshot identity

`MAD4B_SCP_Skill_Snapshot_Identity` computes a stable SHA-256 identity from the governed App ID plus every enabled Skill logical ID, `SKILL.md` hash/size and supporting resource path/hash/size.

The read-only `mad4b/skills-export-status` response exposes:

```text
snapshot_identity.snapshot_digest
snapshot_identity.identity_token
```

Runtime portable exports embed the same token in:

```text
MAD4B-SNAPSHOT.json
MAD4B-SNAPSHOT-ID.txt
```

The exporter is race-closed: it captures the initial identity, hashes the exact bytes written into the ZIP and recomputes the live identity after the read. Any observed-byte mismatch or registry change deletes the temporary package instead of publishing a mixed snapshot.

External snapshot acceptance therefore requires:

```text
WordPress snapshot_identity.identity_token
            ==
installed/exported MAD4B-SNAPSHOT-ID.txt
```

A token match proves snapshot byte/App-mapping parity. It does not prove that an external client loaded or executed the tools.

## Portable export

The WordPress Skills page can export the certified Staging Plugin snapshot containing:

```text
plugin.json
.app.json
MAD4B-SNAPSHOT.json
MAD4B-SNAPSHOT-ID.txt
skills/
  <skill>/SKILL.md
  <skill>/references/...
  <skill>/assets/...
  <skill>/scripts/...
```

The runtime exporter declares `Write` only when the exact-origin governed write authority and write runtime certification are ready. Otherwise it fails closed to the non-write state rather than treating a manifest declaration as authority.

After a Skill or tool surface changes, publish/refresh the Plugin snapshot and run **Scan Tools** again before external acceptance.

## CI runtime proof

`MAD4B Dynamic Skills` provisions disposable Staging and Production WordPress runtimes. Staging proves zero-touch Skills, governed write inventory, approval bootstrap boundaries, WPML-compatible REST query pass-through, deterministic export and Read + Write certification. Production proves the Staging App/Skill/write authority and write tools remain absent.

## Local marketplace

The repository marketplace entry is `.agents/plugins/marketplace.json`. This branch remains wired to the already registered **Staging** App for controlled acceptance.

Do not put passwords, OAuth credentials, access/refresh tokens, private keys or other secret material in Skill files.
