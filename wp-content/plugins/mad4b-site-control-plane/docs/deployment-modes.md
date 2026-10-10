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
