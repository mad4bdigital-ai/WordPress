# IndexFlow Foundation Contract

Status: Foundation / non-authorizing
Compatibility baseline: ETG Dynamic Filter SEO Bridge 0.4.0-alpha.13

## 1. Scope

This contract separates the reusable IndexFlow core from optional vendor adapters and from the ETG Alpha13 compatibility profile. It does not rename the persisted ETG ABI in this Foundation cut.

The following remain compatibility identifiers for this release:

- PHP namespace `ETG\DynamicFilterSEOBridge`
- option `etg_dfsb_settings`
- existing hook, REST/internal, provider and storage identifiers

## 2. Fresh installation

A fresh installation is valid and inert:

- `enabled=false`
- `profiles=[]`
- no frontend authority
- no publication authority
- no required vendor adapters
- no synthesized Tours/Travel profile

`Global OFF + zero profiles` is valid. `Global ON + zero usable profiles` fails closed.

## 3. Configuration lifecycle

Current schema version is explicit. Runtime normalization is read-only. Settings persistence uses the lifecycle-safe storage sanitizer.

Legacy classification:

- recognized ETG Alpha13 signature -> project the legacy-compatible Tours profile;
- existing generic profiles -> preserve them without ETG fingerprint synthesis;
- ambiguous legacy state -> `legacy_configuration_review_required`, Global OFF, explicit administrator resolution required;
- future schema -> `future_schema_unsupported`, Global OFF, no downgrade or overwrite.

Lifecycle fields are not mutable through runtime configuration filters. Data retention defaults to `preserve`. Destructive uninstall is explicit, current-schema-only and disabled on Multisite.

## 4. Capability-first runtime

Profiles declare semantic capabilities. The adapter registry resolves capabilities to optional adapters. Missing optional adapters are diagnostic only. A required unsupported semantic capability fails closed.

Foundation semantic capabilities include filter URL/AJAX state, filtered dataset/result count, presentation dynamic content/theme builder, SEO metadata/schema/social/sitemap, and language hreflang.

Fresh inert installations must not register vendor runtime paths merely because a plugin is installed.

## 5. Language and publication boundary

Core uses `LanguageResolverInterface` with a vendor-free locale fallback. WPML is an adapter.

Rank Math owns SEO metadata/schema/social hooks only. It must not own `wpml_hreflangs`. Hreflang belongs to the language adapter.

Publication result-count probes use the selected language adapter for language-context execution and restoration. Read-only publication summaries and diagnostic inventory must not persist publication or topology caches.

## 6. Large-dataset semantic acceptance

- dataset size `<=100`: `proof_mode=full_ids`;
- dataset size `>100`: `proof_mode=full_digest`;
- maximum digest dataset: 5,000 identities;
- maximum pagination fetches: 100;
- no-progress, duplicate-page or incomplete identity traversal fails closed.

Digest evidence proves both identity set and order.

## 7. Browser acceptance

Browser evidence is bounded to 2 MiB and rejects unknown top-level envelope fields.

A non-authoritative DOM/result observation is an evidence problem, not a product defect:

- incomplete/non-authoritative observation -> `INCOMPLETE_EVIDENCE / OBSERVATION_GAP`;
- authoritative trusted mismatch -> `FAIL / PRODUCT_DEFECT`.

The observer must expose `result_count_authoritative` explicitly. DOM item count is diagnostic fallback only; a verified JetSmartFilters result-count source may be authoritative.

## 8. Authority boundary

This Foundation contract does not authorize:

- profile or SEO authority synthesis from discovery;
- Ready for Review;
- Merge;
- Staging or Production deployment;
- SEO publication or Production activation.
