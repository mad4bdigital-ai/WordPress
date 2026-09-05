# SEO Publication Contract

## Purpose

Publish approved dynamic JetSmartFilters URLs as discoverable SEO surfaces without converting them into WordPress posts/pages and without allowing discovery to create authority.

## Authority boundary

A URL MAY appear in the ETG Rank Math sitemap only when all of the following are true at evaluation time:

1. Global bridge is enabled.
2. The matched Surface Profile is enabled.
3. The profile has exactly one deterministic publication route `{provider, query_id}`.
4. The URL comes from an exact profile-bound, language-bound `indexable_combinations[]` signature.
5. Exact archive path/provider/query/taxonomy-set authority resolves successfully.
6. All Terms resolve in the requested language without translation fallback.
7. Required Post Type authority is bounded and matches the profile.
8. An authoritative filtered result count is available and meets the profile threshold.
9. Content readiness passes.
10. Required Elementor presentation is explicitly verified.
11. The final `IndexingPolicy` decision is `index=true`.

Global OFF MUST return an empty live publication set. Read-only publication preview MAY evaluate candidates while Global OFF but MUST report `authorizing=false` and MUST NOT add them to the sitemap.

## URL source

Publication URLs are generated only from exact approved combinations. No Cartesian taxonomy expansion, traffic-derived URL discovery, wildcard approval or automatic profile mutation is allowed.

The canonical signature is:

```text
profile_id|language|taxonomy=term-slug|taxonomy=term-slug
```

Legacy language-only signatures may remain valid for request-time compatibility where governed elsewhere, but they are not sufficient publication authority.

## Result-count authority

Request-time JetSmartFilters counts may be unavailable during sitemap generation. The publication layer may use the exact JetEngine Query Builder object's final base arguments plus the exact approved taxonomy clauses to execute a bounded background `WP_Query` count. `post_type=any`, missing queries, non-post queries, missing Terms, invalid taxonomies or unreadable args fail closed.

## Rank Math integration

The plugin registers an `etg-filter-seo` Rank Math sitemap provider. The provider follows Rank Math pagination conventions and returns only currently eligible URLs. It may include bounded gallery images.

Sitemap cache invalidation is requested after relevant post, taxonomy relation, term, term-meta or ETG configuration changes. Eligibility is recalculated after invalidation; a URL that no longer passes the final policy must disappear from the refreshed sitemap.

## Metadata contract

For a resolved dynamic surface the same runtime context may supply:

- HTML title / Rank Math frontend title
- meta description
- canonical
- robots index/noindex + follow
- OpenGraph type, URL, title, description and image
- Twitter title, description, image and card type
- CollectionPage JSON-LD
- WPML hreflang alternates

Metadata mutation must retain the existing structural identity guard. Out-of-scope, invalid, missing-term, translation-fallback or Post Type mismatch states must not receive ETG presentation metadata.

## Multilingual discovery

A hreflang target may be emitted only when:

- WPML resolves every source Term to the target language without fallback;
- the resulting target-language term slugs form their own exact profile-bound approved combination;
- the target URL independently passes publication/index eligibility.

`x-default` may point to the approved default-language dynamic URL. Missing target-language authority is omitted rather than fabricated.

## Bounds

Publication evaluation is bounded by the existing profile/combination limits and an absolute 5,000 URL publication cap. No persistent publication cache is authoritative; Rank Math XML cache is only a delivery cache and is invalidated on source changes.
