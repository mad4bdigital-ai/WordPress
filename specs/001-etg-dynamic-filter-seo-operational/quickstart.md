# Generic Operational Quickstart

## 1. Start inactive
Install/upgrade `0.4.0-alpha.4`. The global bridge is disabled by default. A newly created Profile also defaults disabled.

## 2. Discover; do not authorize
Open **Settings → ETG Filter SEO** and inspect read-only Post Type/taxonomy discovery. Discovery only reports WordPress registrations and relations; it never writes a Profile or grants index authority.

## 3. Generate a disabled blueprint
Choose a Post Type and build a Profile Blueprint. Treat it as a draft only. The blueprint is `authorizing=false`, `enabled=false`, and deliberately contains no archive paths, provider/query routes, structural taxonomy sets, or exact combinations.

## 4. Capture and reconcile runtime evidence
Generate/download Runtime Inventory or use:

```bash
wp etg-dfsb inventory > inventory.current.json
wp etg-dfsb reconcile --previous=inventory.previous.json > reconciliation.json
```

Verify the inventory fingerprint and bounds. Treat `blocked_drift` as a stop condition for acceptance work, not as a request for automatic mutation. Disabled candidates and suggested routes are evidence only.

## 5. Bind identity explicitly
For the draft Profile configure:
- exact `archive_paths[]` (Unicode/WPML-safe),
- exact `{provider, query_id}` routes,
- expected `post_types[]`,
- `post_type_authority` (normally `query_builder` for JetEngine),
- taxonomy rules only for taxonomies attached to those Post Types.

Do not rely on separate provider/query lists for new Profiles.

## 6. Configure taxonomy behavior
For each taxonomy configure only what is required:
- semantic `role`, display/gallery priorities,
- `index_single` and minimum result threshold,
- optional business meta constraints,
- optional `field_map` for `seo_title`, `meta_description`, `focus_keyword`, `short_description`, `image`, `gallery`, and `location_level`.

Multiple gallery meta fields are allowed and are aggregated/deduplicated.

## 7. Define structural and editorial authority
Add exact `allowed_taxonomy_sets`, then exact language-aware combination signatures. A discovered or parsed taxonomy is not indexable merely because it is valid WordPress data.

## 8. Simulate adversarial scenarios
Use **Synthetic Scenario Lab** to test happy paths and objections: wrong query, foreign taxonomy, wrong/missing/mixed Post Type, missing translation, paused term meta, thin content, zero/unavailable count, and disabled Profile. A green simulation is not staging certification.

## 9. Preview a real URL
Use **Preview / Explain** with a real relative/absolute JSF URL. Confirm selected Profile, scope, WPML language, observed provider/query, Post Type authority, taxonomy set, combination authority, content readiness, result authority, and IndexDecision.

## 10. Stage before enablement
On staging prove that Query Builder Post Type and authoritative result count equal the rendered listing for representative URLs. Inspect Rank Math title/description/canonical/robots/OG and dynamic shortcodes/breadcrumb. Confirm hreflang remains vendor-owned.

## 11. Enable explicitly and rehearse rollback
Enable only the accepted Profile, then the global bridge when appropriate. Rehearse per-profile disable and global kill switch, followed by page/cache purge where applicable.

Passing simulation, repository CI, or preview does **not** authorize merge or production activation.
