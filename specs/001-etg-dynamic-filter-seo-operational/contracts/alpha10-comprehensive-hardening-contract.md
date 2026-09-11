# Alpha10 Comprehensive Publication Hardening Contract

Version target: `0.4.0-alpha.10`.

This contract hardens the Alpha9 publication architecture without authorizing merge or Production activation. `merge_authorized=false` and `production_activation_authorized=false` remain mandatory.

## Stable plugin identity and upgrade path

The directory and main file remain `etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php`. WordPress recognizing an uploaded newer ZIP as **the same installed plugin** and presenting **Replace current with uploaded** is the preferred and expected upgrade flow. The maintenance fallback is only for hosts that do not expose the standard overwrite action. It requires Global OFF, administrator/plugin-upload capability, exact ETG identity, a newer version, SHA256 byte match, one bounded ETG ZIP root, no traversal/mixed roots, and WordPress core overwrite APIs.

Canonical CI packages embed `release-identity.json` and provenance binding version, Git SHA, tree SHA, deterministic source manifest SHA256, and package SHA256.

## Authority cannot be widened by runtime filters

Runtime extension filters may veto or narrow but must not create indexing authority. This applies to the global configuration, Surface Profiles, allowed taxonomies, exact-combination registry, and the final should-index filter. A PHP filter cannot turn Global OFF into ON, introduce a new profile/route/taxonomy/combination, lower required thresholds, or promote a base `index=false` decision.

External authoritative result counts require contract `etg.dfsb.result-count-authority.v1` and an explicitly configured trusted source. Legacy numeric filters remain untrusted by default. Background publication-count filters may veto a count but may not replace its authoritative value.

## Final-decision metadata binding

ETG may mutate Rank Math title, description, canonical, OpenGraph or Twitter metadata only after the complete IndexingPolicy returns `index=true`. In-scope blocked pages may still receive `noindex,follow`. A filtered URL cannot be indexable while `canonical_mode=archive`; clean filtered routing stays deferred.

## Immutable evidence

Provider observation, Elementor server-rendered content, and three-way result-count parity verification accept only `sha256:<64hex>` references. Each verification is bound to a normalized profile authority fingerprint that includes the running plugin version. Route, taxonomy, combination, content-governance changes or a plugin upgrade stale prior evidence.

## Live Runtime Probe

A non-authorizing probe may be armed for a short bounded window. It observes real filtered frontend requests at multiple lifecycle stages (`wp`, `template_redirect`, early/late `wp_head`, shutdown), recording provider/query observation, request-time count source/authority, language, missing-term/fallback state, Unicode filter diagnostics, query count, elapsed time and memory. While armed it may hash the final server HTML and record only SHA256 + ETG server-rendered markers/robots/canonical, never the full HTML, IP, cookies or request body.

The probe does not itself prove frontend/request/background count parity. A separate reviewed parity artifact remains required.

## Inventory completeness

`etg.dfsb.runtime-inventory.v2` is evidence-complete only when every mandatory source is available, no bounded section is truncated, and Query Builder identity conflict count is zero. Truncation or collisions change the result to the unavailable/incomplete contract and cannot authorize reconciliation.

## Background count and multilingual behavior

Background Query Builder count must preserve existing nested `tax_query` groups under explicit AND composition, use bounded posts queries, retain `suppress_filters=false`, establish/restore the target WPML language, and fail closed on unavailable language/query/post-type/term state. Unicode/translated slugs require live evidence with `missing_terms=[]` and `translation_fallback=false`.

## Sitemap, cache and performance

Only approved, policy-positive URLs may enter the Rank Math provider. Duplicate generated URLs are suppressed as `publication_url_collision`. Cache identity includes plugin version, epoch and configuration revision. ETG/config, posts, terms, term-meta, ACF save and Elementor document save invalidate publication cache/sitemap storage where the host exposes those hooks.

The fresh/default live rollout is 10 URLs, with a separate cold-start candidate-evaluation budget of 50 and default preview 25. Recommended controlled phases are 10 → 25 → 50 → 100 after runtime/performance evidence. Hard publication/storage bounds remain 500.

## Runtime acceptance still required

Static CI is necessary but insufficient. Before any Production activation decision, collect at minimum:

1. complete Runtime Inventory with zero truncation and zero Query Builder identity collision;
2. provider/query timing on representative live filtered requests;
3. request-time authoritative count;
4. frontend = request adapter = background probe count parity, including an existing nested `tax_query` case;
5. Elementor ETG content in server View Source / HTML SHA evidence;
6. real translated/Unicode slugs, hreflang and `x-default` without fallback;
7. Global OFF empty ETG sitemap evidence;
8. bounded Global ON validation beginning at 10 URLs only under a separate explicit validation decision;
9. cold and cached sitemap TTFB, DB query count and memory baseline;
10. cache invalidation after real Term/ACF/Elementor content edits;
11. runtime release identity matching the exact CI provenance.

None of these observations grants merge or Production activation automatically.
