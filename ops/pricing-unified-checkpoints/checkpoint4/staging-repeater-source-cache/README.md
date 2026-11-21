# CP4 — Original published itinerary Repeater cache invalidation (Tours.dev Staging)

## Existing modules, no parallel cache

The **original** `MAD4B_Published_Content_Scope_Cache` class is shared by the current central architecture, but its entire PHP module was not tracked under `wp-content/mu-plugins/` at parent PR #1038. This is a code-reviewed *Staging-specific source snapshot* under `ops/`; do **not** install it on every company through a generic source-file deployment.

Its current original Staging runtime file is:
`wp-content/mu-plugins/mad4b-published-content-scope-cache.php`.
SHA-256 before: `1491167dc2b9e5992897aac25e47e0484bd7b21dfdac6e407c7398905d838af6`
SHA-256 after: `6b70544d1542bc6cfb2c95e65b2292aa23f614390a7f60b5da7deda7ffd4d576`.

Source of bug: original policy allowed scoped invalidation for public Tour post metadata, preventing the shared global generation from advancing; however original JetEngine **Repeater** Query Builder cache still exclusively uses the global generation, with 900s TTL. A standard Tour programme edit in WordPress could therefore update the original `itinerary_item` meta and scope version but return stale repeater content to a normal visitor. Anonymous FastCGI would then cache the stale PHP output again even on `MISS`, while a cache-bypassed query showed current data.

## Selective fix

The exact original source-policy class now hooks `added_post_meta`, `updated_post_meta`, `deleted_post_meta` at priority 90 and accepts **only** exact `itinerary_item` on a **published** `tours-and-activities` post with the governed scoped policy active. It debounces all event occurrences in one PHP request and schedules a single fragment/repeater-generation rotation at shutdown priority 94, after the full WPML/post-meta batch. Original native published source scope receipt still commits at 95. It updates **only** `mad4b_render_query_data_generation` with original invalidation-origin context; it never flushes the external object cache or triggers a site-wide Nginx/CDN purge. Original post/taxonomy scoped Query Builder version keys retain their independent scope.

This is not a replacement for source-to-HTML dependency proofs, signed supplier Cost Receipts, company pricing approval, child policies, availability or booked transportation.

## Verification and rollback

- Hermetic `php test_original_repeater_source_generation.php`: validates ignored unrelated/draft meta, one deferred callback per batch, no premature update, one global repeater generation change with idempotent finalizer.
- Live original `WP-CLI` readback: exact callback present on Staging, priority 90, source hashes match, a deliberately simulated source event (without editing Tour content) triggered a generation change at end of authorized WP request, post-type scoped source generation stayed constant.
- Original WPML programme source for Tour 60629 now has explicit 3/3 Sharm nights, zero Giftun/Orange Bay. HTTP no-query real-browser-style requests gave FastCGI **MISS then HIT**, both with corrected content. Earlier unsafe FastCGI entries were backed up and invalidated **only for these two Tour URLs**, not for the site.
- Reversible live preimage kept at `/opt/mad4b-company-provisioning/reports/cp4-webscrape-20261121/backup-before-original-repeater-source-policy.php`, plus the source snapshots and QA receipts.
- Production Wayfatours currently uses the same prior source-policy bytes but scoped cache policy is **not enabled** there. This source snapshot is NOT a Production deployment. The Wayfa public programme already served current itinerary correctly; preserve that behavior.
- Review and re-implement through company-validated original source.code.release if promoting outside Tours.dev Staging. Do not silently copy the whole MU plugin into other companies.

## Commercial safety

Package stages, supplier Cost Receipts, bookable units, inventory and checkout were not modified by this patch. The edits to the original itinerary were editorial/geo only. Pending supplier contracts, actual transfers, and hotel rates remain explicitly not approved.
