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

The query epoch adapter uses mad4b/render_query/query_version at priority 30, after the existing Published Content Scope filter; this preserves any legacy generation. Neither the Query Governor source code nor the current Post Type epochs were rewritten.

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
- Direct MariaDB QA transaction verified 2 affected nodes, 1 unrelated node retaining its epoch, and zero rows after rollback. This verifies the SQL semantics only, not PHP/Redis/FastCGI end-to-end acceptance.
- Updated isolated graph fixtures: 29 PASS.
