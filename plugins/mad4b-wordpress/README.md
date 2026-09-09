# MAD4B WordPress Plugin + Skills

This package wraps the existing MAD4B WordPress MCP App with reusable workflow Skills while keeping live WordPress data, OAuth, authorization and tool execution in MCP.

## Current safety boundary

- Portable Plugin capability: `Read`
- Local test app mapping: existing **Staging** MCP App only
- Staging Skill authoring: auto-enabled by the Control Plane with no manual `wp-config.php` edit
- Canonical seed pack: auto-provisioned on Staging; no manual Skill creation is required for the base workflows
- Runtime-authored custom Skill creation: WordPress administrator UI only
- ChatGPT MCP Skill tools: read-only (`skills-list`, `skill-get`, `skills-export-status`)
- No Skill create/update/delete MCP tool
- WordPress global mutation authority is not enabled by this package

## Seed Skills

The Control Plane automatically provisions the following enabled seed workflows on Staging after governance schema and append-only audit storage are ready:

- `wordpress-site-diagnostics`
- `wordpress-connection-diagnostics`
- `elementor-dynamic-content`
- `jetengine-content-modeling`
- `wordpress-archive-audit`
- `wordpress-change-safety`

Existing `SKILL.md` files always win. The seed provisioner never overwrites a runtime-authored Skill with the same logical location. Seed writes are audit-recorded and rolled back if the audit commit fails.

Each portable Skill is a directory under root `skills/` with a required `SKILL.md` file. Supporting `references/`, `assets/`, and `scripts/` directories can be included when needed.

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

The files are deliberately stored **outside third-party plugin directories**. Writing into `wp-content/plugins/elementor/`, `jet-engine/`, or another vendor plugin would be fragile because updates can replace those directories. The level + target namespace preserves ownership without mutating vendor code. The storage root can be moved by the `mad4b_scp_skill_storage_root` filter for a MAD4B-controlled deployment.

### Zero-touch Staging authoring

When WordPress reports `wp_get_environment_type() === 'staging'`, the Control Plane automatically enables the local Skill editor. No `wp-config.php` edit is required.

It then provisions the canonical seed pack automatically once governance schema and append-only audit storage are ready. No administrator form submission is required for the base Skills.

Explicit operator configuration still wins. To deliberately disable authoring on Staging, an operator may set:

```php
define( 'MAD4B_SKILLS_EDITOR_ENABLED', false );
```

Production is never auto-enabled and is never auto-seeded. Production authoring still requires both explicit gates:

```php
define( 'MAD4B_SKILLS_EDITOR_ENABLED', true );
define( 'MAD4B_SKILLS_PRODUCTION_EDITOR_ENABLED', true );
```

Supporting `scripts/` authoring remains independently disabled and additionally requires:

```php
define( 'MAD4B_SKILLS_SCRIPTS_EDITOR_ENABLED', true );
```

These flags only control local Skill-file authoring. They do **not** enable `mad4b-content`, `mad4b-write`, `mad4b-admin`, breakglass, or the global mutation gate.

Do not place passwords, access tokens, private keys, OAuth credentials, or other secret material inside Skill files.

## Portable export

The WordPress Skills page can export the currently enabled runtime Skills as a portable Plugin ZIP containing:

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

For runtime-generated exports, bind an already registered ChatGPT MCP App with:

```php
define( 'MAD4B_OPENAI_PLUGIN_APP_ID', 'plugin_asdk_app_...' );
```

The App technical ID is an identifier, not an OAuth token or signing key.

## Dynamic does not mean hot-reloaded in ChatGPT

The WordPress registry is live and dynamic on the site, but packaged ChatGPT/Codex Plugin skills are a **snapshot**. After changing a Skill you must publish another package, or if Skills are imported from the MCP server, deploy the changed server source and run **Scan Tools** again. Installed clients do not continuously re-read a changed `SKILL.md` from WordPress.

This split is intentional:

```text
WordPress / Growth OS
  canonical + runtime Skill files
          ↓
  governed snapshot/export
          ↓
Plugin package / MCP skill import
          ↓
ChatGPT / Codex
          ↕
existing MAD4B MCP App for live data and tools
```

## Local marketplace

The repository marketplace entry is under `.agents/plugins/marketplace.json`. The package in this branch is wired to the already registered **Staging** App for safe local testing. Do not replace the App mapping with a Production App ID as part of this branch.
