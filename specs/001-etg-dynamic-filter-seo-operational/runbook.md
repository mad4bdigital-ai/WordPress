# Operational Runbook — Generic Surface Profiles

## Normal growth path
1. Discover the Post Type/taxonomy inventory.
2. Generate a disabled, non-authorizing Profile Blueprint.
3. Review WordPress object-type relations.
4. Configure exact archive paths and exact provider/query routes.
5. Configure Query Builder Post Type authority and allowed Post Types.
6. Configure taxonomy rules and optional term field maps/business constraints.
7. Configure exact allowed taxonomy sets.
8. Configure exact language-aware combinations.
9. Run adversarial Synthetic Scenario Lab cases.
10. Preview real URLs and verify bounded diagnostics.
11. Validate on staging against rendered listings/metadata.
12. Enable the Profile explicitly; enable global bridge only under accepted rollout plan.

## Profile incident rollback
- Disable only the affected Profile first.
- Purge relevant page/CDN/object caches if present.
- Re-request representative URL and confirm vendor-owned/neutral behavior.
- If identity/authority impact is wider or uncertain, disable the global bridge.
- Preserve the failing URL, Profile revision, decision trace, WPML language, observed provider/query/Post Type, and result-count authority for diagnosis.

## Configuration failure
### Invalid/oversized Profiles JSON
Expected behavior: previous valid Profile authority snapshot remains active; settings error is shown. Do not manually replace it with an empty list during incident response.

### Duplicate Profile IDs / ambiguous authorities
Expected behavior: readiness degraded. Resolve duplicate IDs or overlapping canonical archive+route authorities before enablement.

### Missing exact route/archive path
New Profiles remain invalid/non-authorizing. Do not enable legacy provider/query Cartesian fallback as a shortcut.

## Runtime identity failures
### `query_not_profiled` / `taxonomy_not_profiled`
Inspect Elementor/JetSmartFilters widget configuration and exact Profile route/taxonomy rules. Do not broaden the Profile until the actual surface is understood.

### `provider_query_mismatch`
Verify observed JetSmartFilters provider/query identity. No substring or nearest-match fallback is allowed.

### `post_type_unobserved`
For JetEngine, verify exact Query Builder exists, is a `posts` query, and binds a finite Post Type instead of `any`.

### `post_type_mismatch`
Inspect `get_query_args()['post_type']`. If the Query Builder intentionally returns multiple Post Types, every one must be explicitly allowed by the Profile.

## Taxonomy/business failures
### `taxonomy_set_not_allowlisted`
Add a structural set only after deciding the URL shape should exist as an SEO landing surface.

### `combination_not_approved`
Add the exact language/taxonomy/term combination only after editorial/SEO approval.

### `taxonomy_meta_constraint_*`
Update term business metadata or Profile rule intentionally; high result count cannot bypass this hard gate.

### `content_not_ready`
Fix unique term copy/meta. Do not use the content hook to promote failed readiness; promotion is blocked by design.

## Result authority failures
### `result_count_unavailable`
Inspect JetSmartFilters request state and Query Builder lifecycle. Never substitute taxonomy term counts or unfiltered totals.

### `zero_results`
Keep noindex. Re-enable eligibility only when authoritative filtered inventory returns.

## Multilingual failures
### translation fallback
Complete WPML term translations. Rendering fallback may continue, but index eligibility remains denied.

### translated archive change
Update exact Profile archive authority and re-run ambiguity/readiness checks. Unknown prefixes never inherit authority automatically.

## Pre-RC evidence
- exact remote SHA clean,
- PHP 7.4 + current matrix green,
- all generic smoke/simulation tests green,
- bundled vendor capability checks green,
- real staging Post Type/count authority equals rendered listing,
- real Rank Math output verified,
- WPML/hreflang ownership verified,
- no PHP warnings/notices,
- per-profile and global rollback rehearsed,
- deterministic artifact + exact-head provenance captured.

No successful runbook step implies merge authorization.


## Staging inventory first-pass
1. Keep Global enabled OFF.
2. Open Settings → ETG Filter SEO as an administrator.
3. Generate Runtime Inventory and download the JSON snapshot.
4. Verify `contract=etg.dfsb.runtime-inventory.v2`, `authorizing=false`, and `profile_mutation=false`.
5. Review Post Type/taxonomy relations, active WPML languages/archive paths, and Query Builder structural IDs/Post Types.
6. Treat `post_type_bounded=false`, missing custom query IDs, slug drift, or mixed Post Types as blockers.
7. Build disabled blueprints only after reviewing the inventory; do not enable a profile during T120/T121.


## Inventory reconciliation / controlled growth
1. Keep Global enabled OFF for new/unaccepted surfaces.
2. Capture `wp etg-dfsb inventory > inventory.current.json` or download the admin Runtime Inventory JSON.
3. If a prior snapshot exists, run `wp etg-dfsb reconcile --previous=inventory.previous.json > reconciliation.json`; otherwise use the admin Current Inventory Reconciliation.
4. Reject `invalid_inventory` and investigate `blocked_drift` before editing any Profile.
5. Treat native CPT archive-path mismatch as warning evidence only when the real governed surface is an Elementor/JetEngine Page. Prove the actual archive Page separately in T122.
6. Review disabled candidates. Do not copy suggested routes/archive paths mechanically. Exact authority fields must be filled deliberately.
7. Re-run Synthetic Scenario Lab after every candidate Profile edit while it remains disabled.
8. Archive the inventory fingerprint + reconciliation JSON as evidence; neither artifact authorizes activation.
