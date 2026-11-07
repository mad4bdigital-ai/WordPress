# MAD4B Page Dependency Cache — Staging rollout 0.4.3

## Purpose

Move from coarse content-type invalidation toward a tenant-local reverse dependency graph for page HTML, JetEngine query results and Elementor/JetEngine fragments. This initial rollout **collects and versions** dependencies while retaining the established safe fallback for unobserved or ambiguous relationships. It does not assert that FastCGI page HTML can be purged by WordPress.

## Local integration

File: wp-content/mu-plugins/mad4b-page-dependency-cache.php.
Registry provider: page-dependency-cache.
Policy ID: content.cache.dependency.
Company UI: Company → Adaptive Controls → Dynamic Policy Registry v2; also shows a Page Dependency status section.

Default values (without a user-created Active override):

- strategy = page_fragment_query
- unknown = legacy_scope
- refresh = off
- collect = on
- ttl_hours = 48

Alternative strategies: page_only, legacy_post_type.
Alternative unknown behavior: no_cache (disables persistent JetEngine query cache for unsupported query signatures).
Alternative refresh: low_priority (up to two eligible paths, single-fire scheduled worker, bounded lock).

## Data model

Two separate, site-local MariaDB tables installed through explicit WP-CLI:

- wp_mad4b_pd_pages: unique page_hash, page_path, WPML language, device, node_kind (page/query), epoch, hit_count, coverage, touched/expiry.
- wp_mad4b_pd_edges: unique dep_hash/page_hash pair and dep_key; direct reverse lookup from changed post/term/listing/query membership to page/query nodes.

The hash of the page identity uses pathname + WPML language + mobile/desktop class, never user cookie contents or private booking data. Query identity is a SHA256 of JetEngine query type/id/final query arguments; query argument values are never written into the reverse edge table, only normalized public query-type/taxonomy facets.

Page edges retain 1–7 days by policy; query epochs and query-predicate edges retain 35 days because the current Query Governor permits source TTLs up to 30 days. The native JetEngine query cache generation remains part of the cache key.

Collection only runs on public anonymous GET routes, not logged-in, checkout, cart, account, private REST, AJAX, WP-CLI, WP-Cron or filtered query requests. It records rendered post IDs, JetEngine Listing IDs and current post/term objects, Elementor template widgets, and safe Query Builder predicates even if the query has zero results. Unknown/custom SQL and rate/price sources are not classified as safe public query dependencies.

The collector is bounded to 140 page edges and 24 distinct query signatures per request. MariaDB writes are throttled by Redis locks: pages 120s, query manifests 1800s. Missing or partial observations are explicitly marked unknown.

## Invalidation

A post change invalidates:
- direct post:<id> dependencies;
- query_pt:<post_type> dependencies to catch new/removed post membership and sorting;
- query_tax:<taxonomy>:<term_id> dependencies for associated terms.

A term change invalidates the term and term-query facets. set_object_terms tracks *both old and new* term_taxonomy_ids, preserving old term membership invalidation when a Tour changes destination.

Template and listing changes invalidate their direct tracked dependency nodes. Only matched graph page/query epochs are incremented; unrelated rows keep their original epochs. The current legacy Published Content Scope Cache is NOT disabled because graph coverage and Nginx coordination are not yet certified. The receipt includes dependent keys, affected nodes, partial-coverage fallback and an explicit fastcgi_coordinator field.

Admin-only read-only summary:
GET /wp-json/mad4b-policy/v2/page-dependencies

As of Hybrid v0.5.0, the query version pipeline picks exactly one query-key authority: proven dependency graph at priority 30 or the old Post Type/Taxonomy scope at priority 20. Legacy epochs remain operational, but certified queries no longer include them.

## Nginx FastCGI boundary

The deployed staging Nginx has homepage microcache 30s, Tours archive microcache 15s, native Nginx purge unavailable. Cache key includes host, request URI, method and device class. A WordPress hook cannot make a previously cached Nginx HTML response disappear before its TTL. No Nginx configuration change, unsound cache-file deletion or manual purge was performed.

Therefore Page HTML epochs are **recorded and selectively bumped** but are NOT yet substituted for the Nginx HTML cache. A fully effective instant HTML invalidation requires either an authenticated targeted purge integration or a separate cacheable WordPress-managed page HTML layer whose safety against live guest nonces, currency/market state, quotation and checkout has been validated. The default safe interim behavior is short FastCGI TTL plus existing legacy scope invalidation.

## Refresh worker

The low_priority worker is available but defaults OFF. It chooses no more than two discovered affected pages, uses one Redis lock per page, verifies local path and host, uses an anonymous GET with X-MAD4B-Bypass-Cache=1 and limits timeout. It can warm underlying PHP/JetEngine query work but NOT the Nginx FastCGI entry. It never includes a cookie, admin session, booking quote or arbitrary external URL.

## Verification and acceptance

- PHP syntax checked on the running PHP container.
- 20 deterministic isolated graph fixtures passed, including filtered tours, old/new dependency types, sensitive-source denial, no_cache fail-closed behavior, Elementor template capture and bounded epoch suffix.
- Runtime loaded on Tours.dev Staging and created the two site-local tables.
- Observed initial graph persisted page/query nodes and reverse edges, with completeness explicitly partial for pages.
- No active user-created Registry v2 policy was issued; default strategy is inherited.
- No Production writes.
- WP-CLI concurrency prevented the additional three-node synthetic live transaction acceptance on some attempts; do not claim that acceptance until it returns PASS.
- No browser parity or LCP/CLS acceptance has been measured for this revision; the 10s bypass-GET observation is not proof of performance improvement.

Remaining milestones: complete synthetic selective transaction, improve query membership precision (not just type/facet invalidation), controlled FastCGI coordination, validate fragment-level key invalidation on real components, run browser parity and new Tour/Term/Template/Rate acceptance, then selectively disable the legacy broad epoch where proof is complete.

## Cache correctness and rollback

- To stop collection without removing the code: set mad4b_page_dependency_cache_v1 collect=off (company-local option).
- To stay on legacy behavior: set strategy=legacy_post_type.
- If unknown metadata or query shape cannot be proved, preserve native Query Governor safety and legacy invalidation.
- Removing the MU file does not delete any content, Rate, booking or related WordPress option. The graph tables remain dormant and can be cleaned up by an explicit migration; do not delete while another worker may still use them.

## v0.4.3 precision and observability updates

- Creation of a new Term triggers scoped term/query invalidation.
- Filtered public post queries use matching positive term-ID dependencies rather than post-type membership; unfiltered post queries retain post-type membership.
- Nested taxonomy predicates, OR/NOT IN, meta-dependent ranks, ambiguous statuses and sensitive sources remain unproven and use the legacy safety path.
- A cap of 8,000 query-node rows bounds the stored reverse graph; existing nodes may still update after the cap. With unknown coverage, the legacy fallback stays active.
- Admin-only read-only impact endpoint: GET /wp-json/mad4b-policy/v2/page-dependencies/impact?dependency=post:60623. It returns at most 50 observed node matches and never mutates the graph.
- Direct MariaDB QA and the real WordPress Page Dependency invalidate() test both verified two affected nodes, one unrelated node retaining epoch 1, and zero remaining QA rows after rollback. PHP-to-MariaDB behavior is proven; Redis key invalidation, real HTML, and FastCGI remain unverified.
- Updated isolated graph fixtures: 29 PASS.

## Reproducible WordPress integration QA

The isolated manual test is in tests/manual/test_mad4b_page_dependency_live_transaction.php. It inserts three unique QA graph nodes under a database transaction, invokes the actual Page Dependency invalidator, checks two matching epochs and one unrelated epoch, then rolls the data back. It uses an isolated query dependency instead of an actual Tour post-type key. No published Tour or Rate is modified.

Run on authorized Staging with an available WP-CLI slot:
wp --path=/var/www/html --skip-themes eval-file tests/manual/test_mad4b_page_dependency_live_transaction.php

Verified real Staging response: pass=true, affected=2, unrelated_page_epoch=1, related_page_epoch=2, query_epoch=2, rollback_clean=true, qa_rows_after=0.

The existing Post Type / Taxonomy epoch still influences Query Cache keys. Selective graph epochs work, but the legacy broad invalidation remains on as a fallback. Removing it requires additional predicate/WPML/fragment/FastCGI acceptance.

## Redis epoch integration proof

The manual WP-CLI transaction was extended to seed stale epoch=1 keys in the actual persistent external object cache, call the live selective invalidator, then read fresh scoped epochs. Verified: external_object_cache=true, related page epoch=2, related query epoch=2, unrelated page epoch=1, and zero QA database rows after ROLLBACK. The test removes its scoped Redis keys after rollback. This proves key invalidation and cache readback through WordPress, not Nginx FastCGI HTML purge.


## Hybrid v0.5.0 — default coexistence

Deployed on Tours.dev Staging, November 7, 2026. Default strategy is hybrid. Backward-compatible page_fragment_query also uses certified dependency selection. page_only leaves Query Cache with legacy scopes. legacy_post_type forces the original Post Type engine.

For safe public posts and terms queries, the Published Content Scope filter checks whether the Page Dependency Graph has a complete matching query manifest and every required predicate edge, valid for longer than the result TTL plus 120 seconds. If verified, the old type-wide suffix is omitted and the Graph adds a standalone hybrid-graph-v1 key containing the exact query identity, guard generation and per-query epoch. If not verified, the existing Post Type suffix remains, and the Graph does not append its own version. This avoids redundant invalidation.

The proof has a 90-second memoization cap, rejects unsupported query shapes and requires Redis persistent object caching. Exceeding the graph invalidation fanout or a failed epoch write rotates a shared dependency safety generation, ensuring a missed invalidation does not keep old certified Query keys. Unknown=no_cache disables unproven Query caching; unknown=legacy_scope preserves caching through the existing legacy engine.

Company Management can select Hybrid, Page-only, or Legacy via content.cache.dependency in Dynamic Policy Registry. Query-specific overrides use scope=query and integer Query ID, with the same authenticated REST rules and expected revisions. Listing/widget overrides apply when the internal Query object supplies those identifiers.

The existing fragment renderer retains its native term and template keys. Page HTML is still owned by Nginx FastCGI (30-second homepage, 15-second Tours archive) and is not immediately purged. Rates, Quotes and private/market-dependent requests remain outside public Query caching.

Verification: 42/42 isolated PHP fixtures passed. The actual WordPress integration test built a synthetic query manifest and reverse edges under a database transaction and showed that a complete query used the Hybrid key without a Post Type suffix, an unproven query retained Legacy, and a near-expiry manifest fell back to Legacy. Per-query Registry rules validated without saving any active rule. Registry revision was 0, and the transaction left no QA rows. Reproduce with tests/manual/test_mad4b_hybrid_cache_live_transaction.php.

Rollback: switch content.cache.dependency.strategy to legacy_post_type by a company-scoped Active rule or a company-local maintenance setting. Leave legacy invalidation hooks and graph tables in place. Do not delete FastCGI files based on guessed URL keys.
