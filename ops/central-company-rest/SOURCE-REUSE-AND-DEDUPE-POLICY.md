# Content Hub tenant-source reuse & deduplication policy

**Status:** normative admission policy for all new MAD4B company-content ingestion flows. **Current implementation and tested enforcement:** Tourism Nile Content Hub pipeline on `tours.dev.mad4b.com`, via `MAD4B_Hub_Source_Reuse_Gate_V1` and `MAD4B_Hub_Nile_Publisher_V1`. Other publishers must explicitly integrate an equivalent gate before their rollout; merely including this document does not automatically constrain unrelated plugin paths.

## Order of execution — no publish-before-match

1. Authenticate exact company identity and verify a signed, complete, owner-specific source snapshot. Bound the allowed data classes and scope.
2. Enumerate existing destination taxonomies and native content independently of WordPress-language frontend filtering. **A source numeric term/post ID is NOT an authority** on the target.
3. Resolve each source category against the **correct target taxonomy**, exact normalized slug, exact resolved parent, the authoritative language on both sites, and WPML translation-group membership. Require a unique target match; no guesses, fallbacks, or automatic cross-taxonomy remapping.
4. Reuse canonical term identities and preserve existing destination term labels, parents, assignments, translations and local media. Do **not** insert, duplicate, overwrite, merge, or delete native destination terms.
5. For any tour/post, check explicit company ID + stable source post ID and ownership metadata. Identical slug/title **without provenance** is merely a collision candidate. Do not overwrite or merge it; tag it for manual owner review. Never infer identity from a matching numeric WordPress ID.
6. Where a previously imported Content Hub entry exists with matching company + object kind + source ID, update that entry **idempotently**. Only refresh generated metadata if the source text has not changed; do not rewrite native product or existing site tour content.
7. If the source taxonomy/slug/parent/language/translation group is missing, ambiguous or inconsistent, **deny admission before writes** and retain the existing published snapshot. Do not invent a new matching taxonomy or silently pick a term from another tenant.
8. Historical rate source records may be `publish` in the **admin-only Content Hub** as documentation but must always have `commerce_approved=no`, `bookable=no`, `for_sale=false`, and no checkout/product rate mutation. A separate authorized pricing review is required to create any current sale rate.
9. Record source company, scope, source IDs, canonical resolution, WPML language/translation identity, source hashes, collision review state and a versioned policy contract. Retain receipts and make rollbacks possible without changing other companies' content.

## Decision table

| Source case | Mandatory decision |
| --- | --- |
| Existing unique term, correct taxonomy/parent/language/WPML | **REUSE existing term**, store source→target link |
| Existing term with similar name but wrong taxonomy/parent/language | **REJECT / REVIEW**, no auto-merge |
| Unknown category or broken source hierarchy | **BLOCK ingestion**, do not invent a term |
| Category source has an EN/AR WPML pair | Bind the verified existing target translation pair |
| A tour slug matches an existing destination tour but owner isn't certified | **REVIEW_HOLD** only, keep original native tour untouched |
| Explicit source owner and stable source-post ID verified | May record a link, still no native mutation without separate approval |
| New source tour without destination-native identity | Retain in isolated company-owned Content Hub entry only |
| Existing Content Hub reference matches company/kind/source ID | **UPDATE IN PLACE**, never create a second Hub reference |
| Historical price valid/expired | Content Hub publication permitted; **commercial sale disabled** in both cases |
| Company/locale/data signature incomplete | **DENY**, no donor-value fallback |

## Scope and current receipt

The source `tourism.almallahgroup-mg.com` has **18 category records** representing **9 existing bilingual (English/Arabic) WPML groups**. Every source category now has one unique, validated mapping to existing `tour-types` term on `tours.dev.mad4b.com`; `property-types` records with similar slugs are excluded from the term match. The source taxonomy `tour-types_jet` and target `tour-types` are explicitly mapped in this **tenant-specific adapter**, never globally assumed identical.

**Four** Tourism tour source records have native destination tour slug collisions: source IDs `15081`, `15941`, `24900`, `41909`. Their native candidate post IDs are `60433`, `60435`, `60617`, `60745`. No explicit source-company/source-post provenance was present on those native candidates. They remain `native_slug_collision_requires_owner_review`. Arabic source URL-encoded slugs are supported with strict percent-encoding validation; broadening the regex was required to find the fourth collision.

The 417 admin-only published Hub entries were reconciled **in place**, not reimported: 18 taxonomy reference links, 35 source-owned tour documents, 364 non-bookable historical price documents. No new Content Hub entries, native taxonomy terms, native tour posts or WooCommerce rate/product records were created by the reconciliation. Repeat dry-run: 417 unchanged, zero creates/updates.

## Operating safeguards

- The gate executes inside the Content Hub publisher **before its first write**. It is fail-closed if the gate plugin is missing or source WPML/parent relationships are unverifiable.
- Signed source taxonomy data now includes source-owned WPML language and translation group. A language or group mismatch blocks ingestion instead of silently selecting a similarly named term.
- Company authority and term IDs are **not** inferred from another company's source database, template or local WPML term IDs.
- Seven negative tests were exercised on Staging: foreign tenant, wrong taxonomy, mismatched language, bad parent, duplicated category ID, invalid tour slug and incomplete tour set: all were rejected.
- Source record versioning is a separate concern from public-site distribution. `publish` on the isolated Hub CPT does **not** imply that a public website will display it or that commerce checkout may use it.
- Rollback must restore a reviewed previous **publisher + gate code pair**; disabling the policy alone is **not** an approved bypass.

Source files: `central/195-mad4b-hub-source-reuse-gate.php`, `central/190-mad4b-hub-nile-publisher.php`, and `remote/180-mad4b-tourism-nile-source-read.php`.

Evidence: `evidence/STAGING-SOURCE-REUSE-POLICY-20261029.json`.

**Review/merge control:** PR #631 remains Draft until independent CI and exact-head owner approval. A Staging rollout of this source-specific gate does not constitute permission for Production promotion.

## Owner-confirmed legacy demo: precise override, not a global bypass

On 2026-10-29, the owner identified the existing Staging `Esmerada Nile Cruise` (native post `60617`) and `رحلة نيلية إيسميرادا` (native post `60745`) as **old demo content**. Their respective signed Tourism source tour IDs are `24900` (EN) and `41909` (AR). This confirmation is a *content classification*, not evidence of historical import provenance.

The release defines these **two exact source/native pairs** in a versioned `MAD4B_Hub_Curated_Demo_V1` decision registry (`central/196-mad4b-curated-legacy-demo.php`). The source-reuse gate verifies matching company, native ID, source ID, slug, title, post type, and source language. An unexpected mismatch **fails closed** rather than granting broad demo exemptions. The signed Tourism tour remains the authoritative company source in Content Hub, with state `native_legacy_demo_source_authority`. Neither the demo posts nor their original status, metadata or media are changed; there is no automatic merge, replacement or deletion. This is distinct from public-site distribution.

The other two collisions — Tourism source `15081` → Staging `60433` and `15941` → `60435` — **remain review holds** without sufficient explicit source ownership. Do not generalize the owner's Esmerada classification to them.

Staging reconcile receipt: **417 existing Hub entries retained**, **35 tour governance metadata records refreshed**, **0 new posts**, **0 native posts changed**. Repeat reconciliation: **417 unchanged**. The deliberately altered Esmerada source-title claim was rejected by the gate. See `evidence/OWNER-DECLARED-ESMERADA-DEMO-20261029.json`.

## Staging-only native demo provenance labels and dependency hold

When the owner declares an existing native site tour to be a **Legacy Demo**, the authorized Staging operator may run `MAD4B_Hub_Curated_Demo_V1::apply_native_provenance_labels(true)` to preview, then call the method with `false` only after verifying exact native IDs/statuses and authoritative signed-source Hub entries. It requires `manage_options`, verifies precise signed source identity, and refuses conflicting existing labels. It writes **seven provenance meta fields per exact confirmed native demo**, without updating the native body, status, taxonomy, booking or prices; repeat operations perform no writes.

For Esmerada: `60617` (EN, remains `publish`) points to Hub `67483`, and `60745` (AR, remains `draft`) points to Hub `67484`. Classification and data-source authority are not equivalent to public display suppression. The older published EN post is still referenced in a `jet-engine-booking` rendered-form `tour_id`, the published Favourites page, and a published Elementor sidebar listing template. The booking form record predates the tour's own creation, so it is **not** safe to infer a confirmed paid booking, nor safe to silently archive the page before resolving references. Its dependency review is explicitly held. The two other Luxor/Aswan matching native tours `60433` and `60435` have identical source title/slug/body and selected program fields, but differences in accommodation/description and no verified provenance; they remain review holds.

Private pre-change backup and receipt exist on the Staging VPS, not in GitHub. Evidence without customer or secret data: `evidence/ESMERADA-DEMO-DEPENDENCY-20261029.json`. The initial owner-confirmed demo decision did not authorize deleting a booking-linked record or changing its public permalink. Any later archival step needs its own dependency-safe rollback and review.
