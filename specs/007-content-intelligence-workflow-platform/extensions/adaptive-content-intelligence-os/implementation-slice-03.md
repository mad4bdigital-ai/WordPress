# ACI01 Slice 03 — Read-only opportunity / blueprint candidate

**Source contract**: `mad4b.aci01.opportunity-preview.v1`  
**State**: `SOURCE_IMPLEMENTED_EXACT_PHP_PENDING_STAGING_PENDING`. No external effects.

Reuses the already implemented, governed `mad4b/aci01-evidence-preview` and `mad4b/aci01-intake-preview` abilities. A selected ContentJob supplies brand/locale/market; the caller cannot replace it. The existing WordPress-native profile supplies the eligible post type and whether native taxonomy relations require an independent QA stage. The evidence reader supplies metadata-only bounded source digests, freshness and missing context, not raw text.

`mad4b/aci01-opportunity-preview` accepts one exact ContentJob UUID, one requested native post type, a bounded goal and up to 12 optional existing Artifact IDs. It composes both results into a **non-authorizing `OpportunityHypothesis_BlueprintCandidate`** containing: exact site scope, native content type, hashed goal, exact parent-evidence fingerprints, conditional required section keys, missing evidence reason codes and an operator review handoff.

It does **not** generate an article, assert facts, score growth with invented data, create ContentJobs/Artifacts, run a model, spend external budget, call the governed Blueprint builder, publish to WordPress or claim approved source rights. Even when both readers return apparently complete data, candidate status remains `NEEDS_REVIEW` and `trusted_authority_verified=false`; a genuine draft transition still requires the existing Feature007 Content Intelligence Pipeline and current grants.

Negative fixtures address revoked read policy, upstream denial, extra input, cross-brand/locale scope, forged hashes, uncertain relations, source-free claims, deterministic fingerprint, no sensitive goal echo and no effects. Python/PHP actual exact-head execution and Staging readback are **separate certificates not inferred from source**.

Next milestones: strict, source-level native WPML term/post identity inspection; licensed SERP/Scrape adapter certification and bounded Account budget; factual Editorial/SEO QA; then distinct, owner-approved governed draft/publishing flow. Keep all ACI task registry rows `OPEN` until matching operational certificates exist.
