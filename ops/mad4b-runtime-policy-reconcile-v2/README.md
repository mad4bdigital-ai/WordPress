# MAD4B Runtime & Policy Reconcile v2.4

Source-backed release package from both tested Hostinger deployments.
Company-specific DB, credentials, private host bindings and absolute
site-specific environment paths are not included as configuration artifacts.

## Components
- bin/company-cache-coherence-reconcile.py: read-only live observer.
- bin/company-cache-auto-enroll-v2.py: discovery, canonical SHA checks,
  duplicate-root protection and atomic company enrollment.
- bin/runtime-dependency-preflight-v2.py: 377-module dependency graph.
- systemd/: independent 30-minute/manifest-change enrollment and monitoring.
- overlays/: runtime module source snapshots of 305/306 and 417/420/421.

## Release prerequisites
1. The full 377-module graph and its map contract are installed first.
2. Register each tenant only after project container, WordPress, nginx
   and cache-warm manifest validate with the canonical fingerprint.
3. Preserve local company bindings in private desired-state policy. Do not
   replace the production file with a generic manifest.
4. Run strict shadow observer before deployment, verify all personal
   and booking cookies BYPASS shared cache and are no-store.
5. Run temporary nonbusiness page lifecycle and WPML locale tests.
6. Do not bypass existing owner/operator authorization, force-push master,
   or restart production PHP without a verified rollback.

The package does not auto-grant or silently expand executor authority.


## Anonymous public rendering lane (v2.4)
Only GET/HEAD for clean public home and published-tour paths bypasses
14 central Broker/SSH modules. REST, WP-CLI, authenticated, query and
other requests retain the full 377-module load. The router and approved
control-plane modules are hash-pinned in the live policy.

## Source-backed route inventory (v2.4)
- WordPress eval-file publishes the known public URL inventory to a
  compiled PHP allowlist, not a broad URL regex.
- Exclude checkout, cart, account, favourites, booking, REST, login and
  webhooks. All unrecognized paths receive the original 377-module boot.
- The source route registry refreshes every 30 minutes from published
  WordPress content. A failed refresh leaves the prior verified registry.
- Regenerated route data is deployment-specific and not shipped as
  GitHub configuration; code and compiler hashes are pinned.
- The observer verifies registry size, no private paths, timer activity,
  and the exact source router file SHA.

The rate query/Elementor templates deliberately retain context-aware
dynamic rendering. Do not cache rate HTML across WooCommerce sessions,
markets or currencies without independent pricing parity proof.
