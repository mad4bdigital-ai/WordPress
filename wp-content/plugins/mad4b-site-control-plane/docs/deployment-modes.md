# Deployment modes — WordPress Site Control Plane

This plugin has a **WordPress-dedicated runtime adapter** while the broader
MAD4B Context Authority platform retains the following first-class modes:
`shared_multi_tenant`, `dedicated_isolated`, `dedicated_autonomous`,
`wordpress_dedicated`. These are supported contract variants, **not four active
hosting targets in a single installed WordPress plugin**.

## Local default

When this plugin is installed on an explicitly enrolled WordPress site and
the existing Site Profile confirms origin, environment, deployment binding and
authority readiness, the read-only adapter resolves `wordpress_dedicated`.
It never guesses identity from hostname, URL string, WordPress username,
request-supplied tenant IDs or marketing profile text.

The scope is calculated from Site Profile `site_uuid` and the site-bound
Context Authority's `brand_id`; WordPress blog/network IDs are included in
the scope for multisite safety. A single currently governed Brand Context
Profile is auto-selected. Multi-brand selection **within one WordPress blog**
remains a separate registry/profile feature, not implied by this adapter.

A missing brand, unconfigured Site Profile, clone/deployment mismatch, origin
or environment drift, or untrusted request override yields BLOCKED without
fabricating a scope or elevating privileges.

## Trusted boundary

`MAD4B_SCP_Deployment_Mode_Resolver::resolve($untrusted_scope)` is a
read-only projection. Supplied fields are assertions to compare against
server-derived values; they never set the values. The registered
`mad4b/deployment-mode-status` Ability uses the existing policy's
`can_read` permission. Site Profile enrollment and the registered ability
remain prerequisites.

A resolution is **not** permission for content publication, source import,
credential management, production mutation or a WordPress environment change.
Every operation remains under its existing authorization, audit, external
provider postconditions and production authority gates. Read-only status does
not create a new write path.

## Runtime matrix

| Mode | Primary identity | Local plugin behavior |
| --- | --- | --- |
| shared_multi_tenant | Verified platform tenant and brand | Preserved in portable Context Authority, not implicitly activated by the WP adapter |
| dedicated_isolated | Bound dedicated deployment tenant and brand | Preserved in portable Context Authority |
| dedicated_autonomous | Local sovereign trust and explicit grants | Preserved; offline cannot self-certify |
| wordpress_dedicated | Site Profile, Context Authority brand, blog/network | Auto-resolved on an enrolled WP installation |

## Acceptance probes

Native isolated fixture: `php tests/deployment-mode-resolver-runtime.php`.
Test both origin/deployment drift denial and request-scope spoofing. Run
independent WordPress staging integration before marking plugin runtime
accepted; no test here authorizes a production rollout.

## Dependency closure (required vs optional)

The source-of-truth matrix is `config/deployment-mode-dependencies.json`. These categories have separate consequences:

1. **Identity mandatory:** Site Profile `mad4b.site-profile.v2`, an enrolled and bound deployment identity (both `deployment_binding_bound` and `deployment_binding_configured` and `deployment_binding_match`), exact origin/environment, an enrolled versioned `mad4b.brand-context-profile.v1`, and current WordPress blog/network context. Failure returns `BLOCKED`, never a random tenant/brand.
2. **Discovery mandatory:** WordPress Abilities API, MAD4B read policy and the authorized MCP adapter. These are necessary for connector-side discovery, not for the standalone read-only PHP identity projection.
3. **Capabilities optional:** Google Drive, WooCommerce, Elementor, JetEngine, WPML and SEO providers. Their absence disables relevant capabilities only, with no switch to a different deployment mode, tenant, brand or backup source.

The read-only `dependency_status` diagnostic reports code presence, not certified provider operation. A detected provider means `DETECTED_UNVERIFIED`; the source feature must still pass its own governance and runtime tests. WordPress user capabilities and MCP authorization remain separate from the ability's registration.

A legacy Site Profile that merely reports `deployment_binding_match=true` **without a stored bound identity** is blocked for Dedicated scope resolution. This is a deliberate compatibility fence: re-enroll through the existing governed Site Profile flow; do not fabricate a token or edit the DB.

## Native offline evidence (without CI)

For the plugin alone: `php -l includes/class-mad4b-scp-deployment-mode-resolver.php` and `php tests/deployment-mode-resolver-runtime.php`.

For both repositories checked out at exact pinned commits:

```sh
python tests/deployment-mode-dependencies-contract.py \
  --plugin-root . \
  --core-seed /path/to/context-authority-business-profile-v1 \
  --wp-head <40-hex-wordpress-head> \
  --core-head <40-hex-platform-head> \
  --run-php
```

The cross-repo checker is read-only and fails closed when files, versions, pinned heads or native PHP runtime are missing. Passing it **does not** prove actual WordPress Staging installation, Google Drive integration, browser approval or production release readiness.

## Migration and recovery

Keep existing `Site Profile` records; no automatic DB migration, rewrite of `wp-config.php` or change of `wp_get_environment_type()` is performed. A missing brand record, a stale version, origin drift or a clone must produce an actionable diagnostic and a governed re-enrollment path, not a silent fallback to another brand or a shared platform mode. Binding changes invalidate previously assembled Context Authority projections and trigger independent readback before any future writes.
