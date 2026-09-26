# Alpha9 Publication Governance Hardening Contract

Status: Alpha / Draft / fail-closed
Target: `0.4.0-alpha.9`
Merge authorized: **false**
Production activation authorized: **false**

## Purpose

Alpha9 converts the Alpha8 publication review objections into executable governance. It does not authorize Production indexing. It makes dark validation safer, bounded, and evidence-driven.

## 1. Disabled-profile dark validation

- The built-in Tours profile defaults to `enabled=false`.
- Global bridge defaults to OFF.
- `RequestScope::evaluateForEvidence()` may resolve an exact disabled profile through `ProfileRegistry::resolveForEvidence()`.
- Evidence resolution is always `authorizing=false` and cannot be reused as live authority.
- Live `RequestScope::evaluate()` still requires both Global ON and an enabled profile.

## 2. Provider observation authority

Every profile may require `require_provider_observation_for_index=true`.

For live requests:
- `provider_observed=false` is a hard deny: `provider_query_unobserved`.
- An observed provider/query that differs from the URL/profile route is a hard deny: `provider_query_mismatch`.

For background publication evaluation:
- absence of recorded provider evidence is a hard deny: `provider_observation_not_verified`.
- a verified state without `provider_observation_evidence_id` is invalid.
- evidence IDs are references only; they do not themselves prove the evidence and must be backed by runtime acceptance records.

## 3. Result-count parity

When `require_result_count_parity_for_publication=true`, sitemap publication additionally requires:
- `result_count_parity_verified=true`;
- non-empty `result_count_parity_evidence_id`.

The evidence must show:

`frontend rendered listing count == request-time authoritative adapter count == publication background probe count`

A mismatch blocks publication.

## 4. WPML background count context

The background Query Builder count probe:
- preserves bounded Query Builder Post Type authority;
- sets `suppress_filters=false`;
- enters the requested WPML language through `SitePress::switch_lang()` when a switch is required;
- restores the previous language in `finally`;
- fails closed with `wpml_language_context_unavailable` when a language-bound publication count cannot resolve the active WPML language context;
- fails closed with `wpml_language_switch_unavailable` when a required language switch cannot be performed;
- combines an existing `tax_query` and ETG filter clauses under an explicit outer `AND`.

## 5. Content readiness

New default content floor:
- global fallback: 250 characters;
- default depth 1: 250;
- default depth 2: 400;
- default depth 3: 500.

Profiles may additionally require minimum unique Term-content segments by depth. Failure is explicit as `insufficient_unique_sections`.

These thresholds are conservative minimums, not a guarantee of editorial quality.

## 6. Bounded publication

- Maximum stored exact combinations per profile: 500.
- Maximum live publication candidates: 500 hard ceiling.
- Default global publication rollout ceiling: 100.
- Default per-profile publication ceiling: 100.
- Default Admin preview: 50; hard preview ceiling: 100.

No wildcard or Cartesian combination generation is allowed.

## 7. Persistent publication candidate cache

Publication candidate evaluation uses a bounded transient cache keyed by:
- cache epoch;
- configuration revision;
- profile/signature;
- preview vs publication mode.

The cache is invalidated by an epoch bump on relevant post, taxonomy, term-meta and ETG configuration changes. Rank Math sitemap cache invalidation remains in place.

Cache is an optimization only. It cannot create authority and cannot turn an excluded candidate into an included candidate.

## 8. Preview vs emitted state

Publication Candidate v2 separates:
- `metadata_candidate`
- `metadata_emitted_if_global_on`
- `schema_candidate`
- `schema_emitted`
- `sitemap_included`

`metadataForContext()` includes schema only when the final indexing policy returns `index=true`.

## 9. Structured Profile Manager

A structured Admin surface manages:
- profile enabled state;
- exact single route;
- allowed taxonomy sets;
- exact approved combinations;
- provider-observation evidence;
- Elementor dark-render and verification evidence;
- result-count parity evidence;
- sitemap enablement;
- preview/publication bounds.

Rules:
- `manage_options` required;
- nonce required;
- writes are blocked while Global bridge is ON;
- verification flags cannot be set without evidence IDs;
- multiple-route profiles remain Advanced JSON for route editing to avoid destructive flattening;
- raw JSON remains the advanced authoritative representation.

## 10. Evidence bundle

`etg.dfsb.publication-evidence-bundle.v1` is read-only and non-authorizing. It packages:
- readiness;
- Runtime Inventory;
- publication preview;
- cache/performance metrics;
- profile evidence references;
- activation blockers;
- required external runtime evidence.

It always states:
- `merge_authorized=false`
- `production_activation_authorized=false`

The bundle cannot substitute for real Staging/Production-dark runtime evidence.

## 11. Clean canonical URL deferral

`clean_filtered` canonical routing is intentionally **not** activated in Alpha9.

Reason: clean public URLs require runtime evidence for:
- route collisions;
- rewrite behavior;
- translated permalink behavior;
- canonical equivalence;
- redirects;
- JetSmartFilters compatibility.

Alpha9 preserves only the proven `filtered` and `archive` canonical modes.

## 12. Runtime acceptance still required

Before Ready for Review or any Production activation decision, collect real evidence for:
1. Runtime Inventory v2 completeness.
2. Exact Query Builder/Post Type authority.
3. Provider/query observation on representative live filtered requests.
4. Server-rendered Elementor H1/intro/Term sections in source HTML.
5. Rank Math title/description/canonical/robots/OG/Twitter/schema source output.
6. EN/AR/third-language translated slug and hreflang behavior.
7. Frontend/request/background result-count parity.
8. Global OFF empty live ETG sitemap.
9. Bounded Global ON sitemap inclusion of approved/indexable URLs only.
10. Sitemap TTFB, query count, cache behavior, and memory baseline.

No item above is considered proven by static CI alone.
