# Site-local Elementor JetSmartFilters Consumer Index V2

## Regression repaired

The previous consumer discovery did a `wp_posts` → `wp_postmeta` join using an unindexed `pm.meta_value LIKE '%jet-smart-filters-%'` on every cold 5-minute binding-cache miss and after native filter invalidation. On Woo Lab Staging, that path found 86 Elementor documents and returned 29 filter bindings. The cold call measured 306.1 ms (the reported individual SQL query was 154 ms).

## Exact source and deployment

Source: `mu-plugins/shared-product-core/adapters/presentation-native-consumer-policy-v1.php` in this package.

For the authorized Staging site only (`https://tours.dev.mad4b.com`), deploy to:
`wp-content/mu-plugins/shared-product-core/adapters/presentation-native-consumer-policy-v1.php`.

The source is packaged separately rather than modifying a signed/shared-product-core release manifest. Review/sign/release through the existing governed package mechanism before broad promotion. No Production deploy is authorized by this PR.

## Behavior

- A **one-time bounded legacy scan** establishes the source-accurate list of published `elementor_library` and `page` templates that contain `jet-smart-filters-` in their Elementor JSON.
- The resulting site-specific *ID list only* is held in WordPress option `shared_presentation_native_consumer_ids_v2`, schema `version=2`, with autoload disabled.
- On save, Elementor JSON postmeta changes, status transitions and pre-delete, refresh only the affected post's index membership. Existing consumer transient invalidation remains in place.
- Every normal warm/cold compiled-filter read uses the indexed IDs and runs **zero** `_elementor_data LIKE` queries.
- Binding and permission validation is **not** preapproved by the index: each read still verifies taxonomy, filter source, published status, resolved JetEngine query provider, and WPML canonical translation.
- No new filters, permissions, DB schema migrations, price changes or template edits.
- Missing, invalid, or >1000-result initial discovery fails closed by retaining existing rejection behavior. A fresh install can execute the legacy scan once before the index is seeded.
- The option stays scoped to the WordPress site's own options table and is never shared between tenants.

## Evidence: Woo Lab Staging

| Check | Legacy | Indexed |
|---|---:|---:|
| Cold compiled-binding evaluation | 306.1 ms | 47.13 ms |
| `_elementor_data LIKE` query on cache miss | 1 | 0 |
| Warm compiled-binding evaluation | — | 0.092 ms |
| Published template IDs discovered | 86 | 86 |
| Native filter binding IDs discovered | 29 | 29 |

The full serialized binding result matched byte-equivalent JSON data before/after, including query provider and template ID scope. The index refresh of an unchanged template was idempotent; WordPress registered the save/meta/status/delete hooks; the site option was confirmed non-autoloaded; an additional cold read after refresh had no heavy query. PHP lint passed. Home, Tours archive, Blog and Egypt destination were HTTP 200 in spot checks.

## Release and follow-up

For every site, first verify pre/post bindings and the new index in its *own* database. If a deployment already has a valid index, it is reused, but a one-time per-site bootstrap may be needed if absent. Keep the candidate index and compiled bindings separate. Future provider-query changes still refresh the compiled binding transient on its normal TTL; Elementor content changes update the index immediately.

Full-page HTTP requests can have other bottlenecks; reducing this query is not a claim of overall page-load optimization. Do not infer a business or data owner from filter/query names.
