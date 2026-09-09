# MAD4B WordPress Plugin + Skills

This package wraps the existing MAD4B WordPress MCP App with reusable workflow Skills while keeping live WordPress data, OAuth, authorization and tool execution in MCP.

## Current safety boundary

- Portable Plugin capability: `Read`
- Staging App mapping: auto-bound to the governed existing Staging App unless an explicit operator mapping is present
- Staging Skill authoring: auto-enabled by the Control Plane with no manual `wp-config.php` edit
- Canonical site/connection/workflow seed pack: auto-provisioned on Staging
- Provider Skill packs: discovered, placed, enabled and disabled automatically from live plugin + adapter state
- Local runtime certification: generated automatically and exposed read-only through MCP
- Deterministic snapshot identity: generated from enabled Skill contents/resources + App mapping for exact external package comparison
- Runtime-authored custom Skill creation: WordPress administrator UI only
- ChatGPT MCP Skill tools: read-only (`skills-list`, `skill-get`, `skills-export-status`, `skills-runtime-certification`)
- No Skill create/update/delete MCP tool
- WordPress global mutation authority is not enabled by this package

## Zero-touch Skill lifecycle

On Staging the Control Plane performs the base workflow setup without administrator intervention. It automatically enables the local Skill editor and binds the known Staging OpenAI App ID unless an explicit operator configuration already exists.

```text
Plugin boots
   ↓
Staging autoconfig
   ↓
Skill editor + Staging App mapping
   ↓
Governance schema + append-only audit ready
   ↓
Base site/connection/workflow Skills provisioned
   ↓
Installed plugin discovery
   ↓
MAD4B adapter registry readback
   ↓
Provider Skill catalog reconciliation
   ↓
Active + adapter-ready provider → Skill enabled
Inactive/unavailable provider       → MAD4B-managed Skill disabled
   ↓
Deterministic snapshot identity
   ↓
Local runtime certification
```

No `wp-config.php` edit is required on Staging. No administrator form submission is required for this lifecycle.

The base pack includes:

- `wordpress-site-diagnostics`
- `wordpress-connection-diagnostics`
- `wordpress-archive-audit`
- `wordpress-change-safety`

Elementor and JetEngine definitions are seeded disabled and handed to provider discovery, so they are enabled only when their providers are active and the corresponding MAD4B adapter is runtime-available.

## Provider-aware Skill packs

The provider catalog is stored at:

```text
wp-content/plugins/mad4b-site-control-plane/config/skill-provider-catalog.json
```

Current automatic families include:

- Elementor
- JetEngine
- JetSmartFilters
- WooCommerce
- Polylang
- Rank Math
- LiteSpeed Cache
- media optimization providers
- ETG Dynamic Filter SEO Bridge
- BitFlows
- Fluent Forms
- WPML

The catalog can define a Skill at any supported registry level: `site`, `connection`, `provider`, `adapter`, or `workflow`. Placement follows the Skill definition, for example:

```text
wp-content/mad4b-skills/provider/elementor/elementor-dynamic-content/SKILL.md
wp-content/mad4b-skills/provider/jet-engine/jetengine-content-modeling/SKILL.md
wp-content/mad4b-skills/provider/jet-smart-filters/jetsmartfilters-query-audit/SKILL.md
wp-content/mad4b-skills/provider/woocommerce/woocommerce-catalog-diagnostics/SKILL.md
wp-content/mad4b-skills/adapter/litespeed/litespeed-cache-diagnostics/SKILL.md
```

Provider discovery reads `MAD4B_SCP_Plugin_Discovery::coverage()` after adapter registration. A provider Skill is automatically enabled only when the provider is active, its MAD4B adapter is registered, and that adapter is runtime-available.

If a provider becomes inactive or its adapter becomes unavailable, only MAD4B-managed Skill metadata is disabled. The Skill file is not deleted. When the provider becomes ready again it is re-enabled automatically.

Existing administrator-authored Skills always win. Provider discovery does not overwrite, disable, move, or delete a Skill it does not own. All automatic create/activation changes are audit-recorded and rolled back when the audit commit fails.

## Automatic local runtime certification

The Control Plane records a digest-bound Staging certification after the Abilities/MCP runtime is initialized. The certification checks local facts including:

- Staging environment and Skill editor state
- scripts authoring remains disabled
- managed Skill storage is initialized and writable
- exact governed Staging App mapping is bound
- base seed pack is ready
- provider reconciliation is ready and non-mutating toward provider plugins
- required base Skills are enabled
- all read-only Skill abilities are registered
- no Skill create/update/delete/write ability is registered
- the portable snapshot has enabled Skills
- deterministic snapshot identity is available and its Skill count matches the portable snapshot

Read it through:

```text
mad4b/skills-runtime-certification
```

Certification transitions are append-only-audited and persisted only when their evidence digest changes.

This certification is deliberately `local_runtime_only`. WordPress cannot truthfully prove that a remote ChatGPT/Codex client has installed or refreshed a portable Plugin snapshot. That final client-side snapshot import remains outside the WordPress trust boundary.

## Deterministic snapshot identity

`MAD4B_SCP_Skill_Snapshot_Identity` computes a stable SHA-256 identity from the enabled Skill set. The digest includes the governed App ID plus every enabled Skill logical ID, `SKILL.md` hash/size, and each supporting resource path/hash/size. Timestamps and presentation-only metadata are excluded so the identity remains stable for identical content.

The read-only `mad4b/skills-export-status` response exposes:

```text
snapshot_identity.snapshot_digest
snapshot_identity.identity_token
```

The portable runtime export includes the exact same token in:

```text
MAD4B-SNAPSHOT.json
MAD4B-SNAPSHOT-ID.txt
```

The exporter computes the identity before reading the files and recomputes it after the last Skill/resource is read. If the token changes during the export, the ZIP is discarded and the export fails closed with `mad4b_skill_snapshot_changed_during_export`; a mixed or stale package is never published.

The final external-client acceptance can therefore compare one value instead of manually comparing every Skill file:

```text
WordPress snapshot_identity.identity_token
            ==
installed/exported MAD4B-SNAPSHOT-ID.txt
```

An exact match proves the package represents the same enabled Skill contents/resources and App mapping. It still does not prove that the client executed a Skill successfully; the live ChatGPT/Codex read flow remains a separate acceptance check.

## Dynamic WordPress registry

The WordPress plugin adds **MAD4B Control Plane → Skills**.

Runtime Skills are stored as real files under:

```text
wp-content/mad4b-skills/
├── site/_site/<skill>/SKILL.md
├── connection/<target>/<skill>/SKILL.md
├── provider/<plugin-or-provider>/<skill>/SKILL.md
├── adapter/<adapter>/<skill>/SKILL.md
└── workflow/<workflow-family>/<skill>/SKILL.md
```

A `.mad4b.json` sidecar stores bounded registry metadata next to each Skill.

The files are deliberately stored **outside third-party plugin directories**. Updates to Elementor, JetEngine, WooCommerce, or other vendor plugins therefore cannot erase MAD4B workflow files.

Production is never auto-enabled, auto-seeded, provider-auto-provisioned, or bound to the Staging App. Supporting `scripts/` authoring also remains separately gated. None of these features enable `mad4b-content`, `mad4b-write`, `mad4b-admin`, breakglass, or the global mutation gate.

Do not place passwords, access tokens, private keys, OAuth credentials, or other secret material inside Skill files.

## Portable export

The WordPress Skills page exports enabled runtime Skills as a portable Plugin ZIP containing:

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

On Staging the existing governed App ID is bound automatically, so `.app.json` does not require a manual `wp-config.php` constant for the normal Staging path. Explicit valid operator mapping still wins, but local runtime certification blocks if it drifts from the governed Staging App expected by this branch.

The site registry is live and dynamic, but packaged ChatGPT/Codex Plugin Skills remain a **snapshot**. After a Skill changes, publish another package or redeploy the MCP Skill source and run **Scan Tools** again.

## CI runtime proof

`MAD4B Dynamic Skills` includes a disposable WordPress Staging runtime job. It installs the exact repository MCP Adapter and Control Plane, then proves automatic editor/App bootstrap, seed creation, provider handoff, deterministic snapshot identity, read-only Abilities, certification persistence, and absence of Skill write abilities without manually defining any Skills or App mapping.

## Local marketplace

The repository marketplace entry is under `.agents/plugins/marketplace.json`. The package in this branch remains wired to the already registered **Staging** App for safe testing.
