# Mandatory Brand Core recovery before MAD4B-managed publication

**Scope:** Feature007, PR #258; Staging source change only until exact-head deploy and native acceptance.
**Applies to:** content-experience publish and live-creation profiles, core `mad4b/content-update-post` transitions and live edits, and core `mad4b/content-create-post` with `publish`/`private`. Unrelated native WordPress admin/editor publication is **not** intercepted. Do not describe this as a site-wide `wp_insert_post_data` policy.

## Runtime contract

On every covered request the executor calls `MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight()`. It uses `MAD4B_SCP_Context_Authority::brand_core_coverage()` as the authority source. All of `brand_strategy`, `tone_of_voice`, and `editorial_guidelines` must have non-conflicted, current, exactly reviewed, governed assets. Public competitor pages, WP operational notes, unreviewed legacy sources and client assertions are not authority.

If coverage is incomplete, the guard **automatically calls** the existing read-only `Brand_Core_Control_Loop::status()` to discover the next governed recovery ability and returns `mad4b_publication_brand_core_recovery_required` with `BLOCKED_BRAND_CORE_RECOVERY`, missing/conflicting categories, and a resumable `next_ability`. It does not silently generate or approve Brand Strategy, transfer original Google Drive ownership, persist a write job, or waive per-mutation authorization. The separate governed recovery executor/AI-review lane may resume only with original-owner evidence and exact approvals. There is no automatic retry loop or self-approval.

On valid coverage the guard emits a request-local plan fingerprint of all reviewed asset content hashes, authority manifest, Context fingerprint, and registry revision. Content Experience includes this fingerprint in its exact plan; it checks again after locking and before final publication, including after helper/media writes (compensation required on drift). Core Content Modeling checks in authorization/preflight and again immediately before public creation. Mutation Manager checks before creating a mutation record and immediately before the `wp_update_post` transition. New live edits are covered even if the caller omits `post_status`.

This change does **not** enable Production grants, Developer execution, Breakglass, third-party supplier resale, image licenses, price/availability claims, or publication of Memphis Tours materials. It does not make WordPress administrator manual publish flows dependent on MAD4B.

## All Royal Egypt live precondition

The Staging Context registry at 2026-10-11 had revision 6, one quarantined source and twelve quarantined assets with *zero* approved assets for all three categories. The expected first publish attempt returns `BLOCKED_OWNERSHIP_QUARANTINE` and `context/legacy-reconciliation-census`; it must not publish a Nile cruise.

After verified original ownership transfer/restore, finish approved Brand Strategy, evidence-bound drafts for Tone of Voice and Editorial Guidelines, independently authorized AI/human reviews, source scans, coverage readback, and separate CPT/media/WPML/rights review. **Do not** infer owner consent from this document or from the fact that the user requested a publication.

## Regression and acceptance

Run from repository root:

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-brand-core-control-loop.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-experience-runtime.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mutation-manager.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-core-content-modeling-adapter.php
php wp-content/plugins/mad4b-site-control-plane/tests/publication-brand-core-mandatory-runtime.php
python wp-content/plugins/mad4b-site-control-plane/tests/feature007-manual-preflight.py --help
```

Native PHP 7.4/8.3 acceptance plus exact-head Staging installed-sha readback remain required; a source-only commit or successful test fixture is not a release certificate. On Staging, test blocked public Core create (no post created), blocked Content Experience publication (no status change), blocked Core update and published-post edit (no mutation record), successful authorized draft-only paths, positive three-category approval, race/content-hash drift, independent remediated-source readback, and rejection of unlicensed competitor images/prices. Additional independent acceptance is required for other plugin/native/manual publication surfaces not covered by this slice.
