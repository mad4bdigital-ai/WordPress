# Tour Highlights — native JetEngine workflow (v1)

## Scope
- The existing `tours-and-activities` CPT owns the Highlights tab. New post-meta keys start with `tour_`.
- The existing `highlight_rows` legacy WYSIWYG is migrated *byte-for-byte* into `tour_highlights_content`. Existing language-specific copy stays on its translated post.
- The old field is removed from the JetEngine authoring UI only after every consumer is rewired and exact content readback succeeds. Retain original postmeta and a site-scoped backup for rollback until all historical consumers are retired.
- `tour_highlights_items` is the single JetEngine repeater store; do **not** enable `repeater_save_separate`. Suggestions are drafts for editorial review, not publishable facts.
- `look-and-feel` owns optional company default `tour_highlights_default_mode` (safe default: manual); a Tour can override it through `tour_highlights_mode`.

## Modes
- `manual`: render the existing curated WYSIWYG verbatim.
- `assisted`: generate source-linked candidates on Tour save, all in `needs_review`. Render only approved rows once the whole Tour locale is approved; otherwise preserve the existing WYSIWYG.
- `auto`: render only approved, currently source-valid rows; no fallbacks to unreviewed content.
- `hide`: hide Highlights output and section.
- `inherit`: use the company default; if absent, use `manual`.

## Evidence
Candidates come from the current Tour, its attached destinations and active company term eligibility, linked Programs with an actual `program_itinerary`, and a valid linked Cruise Property. Never infer included temple visits, pickups, onboard facilities, optional services, or payment promises from marketing text. The system does not call an AI API on the frontend.

Approved linked rows must still pass source validity at render time. Editorial `legacy` and `custom` rows require explicit row approval and the locale-wide `tour_highlights_review_status=approved`.

## WPML
- Translate: `tour_highlights_content`, `tour_highlights_intro`.
- Copy once: `tour_highlights_items`, `tour_highlights_mode`; never keep English and other languages automatically synced.
- Independent review per language: `tour_highlights_review_status` is not translated or copied.
- Existing translations remain independent. A translated linked object must not be guessed when no valid WPML mapping exists.

## Elementor
Keep the existing JetEngine Dynamic Field widget; set `dynamic_field_post_meta=tour_highlights_content`, `dynamic_field_filter=yes`, `filter_callback=tour_highlights_render_approved`. Preserve layout, styles, and anchors. For portable templates, mark the parent container `tour-journey-discovery-editor`; set component roles `tour-journey-heading-editor`, `tour-journey-content-editor`, and `tour-journey-related-editor` on its existing three native children. Deployed templates retain compatibility with the original ID `d90c3d1`, but new copies must not depend on element IDs.

## Rollout / verification
1. Snapshot each site's CPT schema, Look & Feel option-page schema, relevant Elementor documents, per-post source metadata, and affected PHP consumers.
2. Apply JetEngine fields via site-scoped, compare-and-swap migration; do not overwrite newer company-specific schemas.
3. Copy legacy data exactly and create reviewable suggestions (never mark them approved automatically).
4. Apply WPML preferences, deploy the callback, and update exact Elementor widgets. Verify all migrated content hashes and no stale Elementor references.
5. Test full save/readback of a draft Tour, manual parity, assisted approval, unverified row rejection, auto-without-approval, company activation, translated locales, published HTTP 200 and mobile layout.
6. Keep code aligned with the current live origin before a future release. **GitHub's default master may lag behind the actual rc.128+ runtime**. Never overwrite a live tree from a stale checkout.

## Rollback
- Restore the exact per-site CPT/Elementor backup and PHP consumer versions only after checking the latest live fingerprints.
- Deactivate the new callback or revert the changed JetEngine callback to the prior rendering only if required by a verified incident.
- Avoid deleting old `highlight_rows` postmeta until historical evidence consumers and tenant translations have been independently audited.

This document describes the contract, not an authorization or proof of an independent production release.

## Journey Discovery — integrated destination discovery (November 13, 2026)

- **One section, two native data streams:** the existing JetEngine Dynamic Field (`tour_highlights_content`) and native Elementor Template widget with JetEngine destination listing grids. Move that template as a child of the Highlights container; do not clone grids, queries or templates.
- **Four modes:** `explorer` (highlights and tabbed places), `split` (desktop side-by-side), `story` (destination-first order), and `compact` (condensed cards with accessible detail toggles). Tour `inherit` uses Look & Feel `tour_highlights_default_pattern`, falling back to `explorer`.
- **Editor fields:** `tour_highlights_display_pattern` on the Tour Highlights tab, and `tour_highlights_default_pattern` at company Look & Feel. All new Tour meta names use `tour_`.
- **Verified evidence:** use the current Tour's destination terms, `MAD4B_Company_Term_Eligibility_V1::decide` and term links. Link a highlight to a place only when its localized place name is present in its actual text. A linked term is not proof of an included itinerary stop.
- **Low-cost frontend:** destination tabs reuse already-rendered native Elementor/JetEngine listings, without cloned queries, AJAX calls, or a new slider library. Accessibility includes ARIA tabs, keyboard navigation, and native presentation selector.
- **Empty-state contract:** hiding Highlights or having no approved Auto rows must never suppress eligible related destinations. Hide the combined section only when both sources are empty.
- **Translations:** keep each locale's native Elementor template ID. WPML `tour_highlights_display_pattern` is `copy-once`, not a live copy across translations.
- **Cache:** DB CAS migration invalidates per-document Elementor HTML caching and the affected tour-page FastCGI generation files (`wp-content/.mad4b-purge/versions/<localized-tour-path>/.generation`), not a global purge.

## Repeatable migration and acceptance tests

- `scripts/migrations/tour-journey-role-migration.php`: **the single canonical** role-based Elementor discovery/migration, site-identity check, CAS updates, and `TOUR_JOURNEY_ACTION=preflight|apply|verify`. Snapshot before writing. The byte-identical duplicate `tour-journey-discovery-roles.php` was consolidated out of the unified source; its prior Git history remains intact.
- `scripts/qa/tour-journey-acceptance.php`: read-only role, native destination listing, translation and WPML check. Usage: `TOUR_JOURNEY_SITE=https://your-site.example wp eval-file scripts/qa/tour-journey-acceptance.php --allow-root`. Optional `TOUR_JOURNEY_EXPECT_GROUPS=<approved count>` enforces that site's current expected group count; absent it, one or more existing native groups are required, without a fixed fleet-wide count.

**Observed results:** Wayfatours 3/3 native Elementor Tour documents; Tours.dev 7/7, with one combined section, one Highlights field, one native Elementor destination template per Tour document and five categorized groups in each related template. Role migration was idempotent. Live browser QA on Wayfatours EN+ES at 1440px and 390px returned HTTP 200 and found four working presentation modes and three switchable destination tabs without reported JS errors or horizontal page overflow. Tours.dev published five-day Nile cruise was HTTP 200 with two visible destination groups inside the combined section.

**Still separate from repository review:** the PR is a draft; no independent signed release or PR merge has been approved. Visual/browser QA of every WPML language and individual Tour subtype is not complete. A QA draft requiring an authenticated preview session cannot be validated through an anonymous public preview URL.
