# MAD4B WordPress Plugin + Skills

This package wraps the existing MAD4B WordPress MCP App with reusable workflow Skills while keeping live WordPress data, OAuth, authorization and tool execution in MCP.

## Current safety boundary

- Portable Plugin capability: `Read`
- Local test app mapping: existing **Staging** MCP App only
- Staging Skill authoring: auto-enabled by the Control Plane with no manual `wp-config.php` edit
- Canonical site/connection/workflow seed pack: auto-provisioned on Staging
- Provider Skill packs: discovered, placed, enabled and disabled automatically from live plugin + adapter state
- Runtime-authored custom Skill creation: WordPress administrator UI only
- ChatGPT MCP Skill tools: read-only (`skills-list`, `skill-get`, `skills-export-status`)
- No Skill create/update/delete MCP tool
- WordPress global mutation authority is not enabled by this package

## Zero-touch Skill lifecycle

On Staging the Control Plane performs the base workflow setup without administrator intervention:

```text
Plugin boots
   ↓
Staging autoconfig
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
```

No `wp-config.php` edit and no administrator form submission are required for this Staging lifecycle.

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

Production is never auto-enabled, auto-seeded, or provider-auto-provisioned. Supporting `scripts/` authoring also remains separately gated. None of these features enable `mad4b-content`, `mad4b-write`, `mad4b-admin`, breakglass, or the global mutation gate.

Do not place passwords, access tokens, private keys, OAuth credentials, or other secret material inside Skill files.

## Portable export

The WordPress Skills page exports enabled runtime Skills as a portable Plugin ZIP containing:

```text
plugin.json
.app.json                 # only when an App ID is configured
MAD4B-SNAPSHOT.json
skills/
  <skill>/SKILL.md
  <skill>/references/...
  <skill>/assets/...
  <skill>/scripts/...
```

The site registry is live and dynamic, but packaged ChatGPT/Codex Plugin Skills remain a **snapshot**. After a Skill changes, publish another package or redeploy the MCP Skill source and run **Scan Tools** again.

## Local marketplace

The repository marketplace entry is under `.agents/plugins/marketplace.json`. The package in this branch remains wired to the already registered **Staging** App for safe testing.
