# Dynamic Filter SEO Bridge — generic source candidate

This PR introduces `wp-content/plugins/dynamic-filter-seo-bridge` as a **separate, tenant-neutral source tree**. The existing `etg-dynamic-filter-seo-bridge` remains unchanged for existing installations and old serialized Elementor consumers.

- Source snapshot: 180 source files, from ETG WooCommerce Local Lab, version `0.4.0-alpha.14-local.1`.
- Local test build commit (different repository history): `4329adbc7f88e32bc24dd2cc36ee991d3a77261b`. **This is not a GitHub identity.**
- `tools/dfsb-neutral/build_candidate.py` emits a deterministic ZIP whose embedded identity uses **the actual GitHub commit and repository tree** at build time. No pre-existing identity is committed as a source file.
- CI proves exact source, manifest count/hash parity, deterministic package bytes, tamper rejection, and that Full Boot cannot become ready without governed provenance.
- The CI artifact is **UNSIGNED, NOT A RELEASE**. It must not be deployed to staging or production, merged automatically, or used to open Full Boot without Control Plane approval and a signed release package provenance.
- Confirm compatibility across Rank Math, Elementor, JetEngine, JetSmartFilters, WPML, WooCommerce and MAD4B Content Readiness; isolate production data and preserve per-tenant pricing authority.
- The Local Lab's `MAD4B Content Readiness` integration includes an external source hook not yet confirmed in this repository; it needs a separately reconciled SCP provider PR rather than overwriting repository code with live server files.

Manual release sequence: PR review → green CI → approved merge → exact GitHub commit package → governed producer receipt and tenant-specific staging certification → controlled Full Boot approval → production rollout per tenant.
