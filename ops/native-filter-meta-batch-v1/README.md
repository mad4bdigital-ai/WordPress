# QA: Native JetSmartFilters consumer SQL / Elementor metadata priming

Date: 2026-10-29. Reported target: `staging.egypttourgates.com` (Hostinger WordPress PHP 8.5), **not** the VPS Woo Lab Staging `tours.dev.mad4b.com`. The target's screenshot showed 2,756 database queries, of which three were marked slow.

## Source and root cause

The company-neutral function `shared_presentation_native_consumers_v1()` in the shared product presentation adapter scans published Elementor page/library documents containing JetSmartFilters widgets. It currently runs one bounded `LIKE '%jet-smart-filters-%'` query every five-minute transient miss, then loads `_elementor_data` for each template using `get_post_meta()`. Each WP metadata fetch can prime **all** metadata keys of a post, needlessly increasing SQL queries and cache payload. The effect is potentially very large on content-heavy sites.

The source fix **preserves the existing discovery query, cache key, TTL, invalidation hooks and functional bindings**. It adds a single bounded batch query fetching only `_elementor_data` for candidate IDs, in `meta_id ASC` order, preserving the first-meta-value behavior even if a document has duplicate `_elementor_data` records. The Elementor walk uses those exact payloads without per-document `get_post_meta()` calls. It does not remove JetSmartFilters, Fluent Forms, provider rules or WPML translation support.

This is **not** a fix for the separate 15,515-row `update_meta_cache()` Query Monitor row attributed to JetSmartFilters, and it does not change `wp_fluentform_form_meta` for form ID 8. Those have different callers and should be profiled separately on the target site. The three screenshot slow queries total only ~0.292 seconds; they do not establish a cause for the whole ~13-second WordPress request and its 2,756 queries.

## Verified on independent VPS Staging data

Source compared against a copy of the previous production-like function in a bounded WordPress process. Both used isolated QA-only transient keys; they did not modify content and removed those QA transients after each run.

- Same 29 filter IDs/bindings and 44 linked template IDs (SHA256 of returned bindings identical).
- Previous implementation: 167 SQL queries in a cold run, 289.353 ms.
- Selective meta batch: 5 SQL queries, 75.416 ms.
- Warm-cache paths: 0 SQL queries in both.
- PHP lint: successful.
- The earlier one-query candidate also passed parity but was replaced with this safer two-query approach to preserve duplicate-meta semantics.

These are **single-run lab measurements**, not statistically valid PageSpeed, TTFB or target Hostinger timings. The actual `staging.egypttourgates.com` site has not been modified by this source PR or VPS QA. No Production change.

## Release requirements

1. Review the new canonical source path and verify it is included in the governed MU packaging/deployment pipeline; a GitHub file alone must not be described as installed.
2. Run a source checksum/parity review on the actual target's installed adapter.
3. Apply only to the intended Hostinger Staging site through an authorized installer and check login/admin, Elementor, JetSmartFilters, WPML filter translations, query provider mappings and forms.
4. Collect Query Monitor before/after on the **same uncached target page** (the admin toolbar can expose different queries), including SQL total, duplicate SQL and TTFB. Do not disable required page widgets just to reduce query count.
5. Roll back exactly this adapter file on mismatch; preserve all WordPress posts and database metadata.

Run `python3 ops/native-filter-meta-batch-v1/test-contract.py` and `php -l wp-content/mu-plugins/shared-product-core/adapters/presentation-native-consumer-policy-v1.php` in CI.
