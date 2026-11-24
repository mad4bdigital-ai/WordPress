# WPML Program name translation — source/Wayfa live reconciliation (22 Nov 2026)

**Scope:** The user needs editable localized **Program names**, not translatable numeric taxonomy IDs. Source Tours.dev supports EN/ES/FR/DE/IT/AR; Wayfatours supports EN/ES. Both live companies have the same original English canonical Program IDs (2348, 2349, 2350; Nile parent 2351).

## Root cause

1. `related_program_terms` stored numeric taxonomy IDs in Tour post meta, not customer-facing language text. It lacked an explicit WPML Copy preference, so could appear in translation workflows as numeric text.
2. WPML `programs` taxonomy was **already translatable** (`taxonomies_sync_option.programs = "2"`). Existing translated terms were present, but had their original English names until a translator edits them; source Arabic names were already localized.
3. Three child Programs per locale had `parent=0` instead of the correct localized Nile parent, and their translated term meta did not contain the source Program itinerary. The old `MAD4B_Nile_Program_First_Editor_V1::program_options()` queried terms/itinerary from the current locale, so the Cruise Program selector had **0 options in Spanish** even though it listed **3 in English**.

## Implementation on both live sites

- `wp-content/plugins/mad4b-pricing-registry/wpml-config.xml`: `related_program_terms` and `program_term_id` are explicitly **Copy**, not Translate; no itinerary/title text was made nontranslatable.
- Native WPML settings `icl_sitepress_settings.translation-management.custom_fields_translation` were set to numeric `1` for those two reference fields after saving complete preimages, and validated by readback. `programs` remains translatable.
- `wp-content/mu-plugins/mad4b-nile-program-first-editor-v1.php`: resolve **itinerary eligibility and keys from the source locale**; display localized `programs` term names in the editor. `program_options` keeps stable source keys `2348/2349/2350`, and the active language is restored even if resolution fails. The editor now links to `WPML → Taxonomy Translation → Programs` and explicitly explains the ID/name distinction.
- Source-language Nile child→translated-parent hierarchy repaired in database without rewriting names, slugs, Tour selections, term itineraries, commercial Rates or checkout. Source: **15** translated child links checked (1 repaired in an isolated trial, 14 in the batch). Wayfatours: **3** translated child links repaired. Repairs were guarded by matching WPML `trid`, language, taxonomy and original source tree. Names and slugs read back identical.

## Translation task for editors

**Wayfatours:** `/wp-admin/admin.php?page=sitepress-multilingual-cms/menu/taxonomy-translation.php&taxonomy=programs`

**Tours.dev:** same path on `https://tours.dev.mad4b.com`.

Choose **Programs** taxonomy and target language, then edit the human-facing *Term Name* (e.g. Spanish `3-Nights 4-Day Itinerary`), not `2348` or `2445`. The translated term has its own ID internally and stays linked through WPML. The original English itinerary structure and business IDs remain source-owned.

Actual program names on ES/FR/DE/IT remain untranslated until entered by an editor. Arabic terms already have names in Arabic. Do **not** claim the translations were written automatically.

## QA / acceptance and safeguards

- Source: 6/6 locales (EN/ES/FR/DE/IT/AR) show 3 program choices with canonical numeric IDs and locale-specific names where present.
- Wayfatours: 2/2 locales (EN/ES) show the same 3 program options, no empty Spanish dropdown.
- All 18 localized Nile child/parent relationships read back correct; the editor's original language is restored after each query.
- WPML preferences `Copy=1` for both ID fields; Programs taxonomy remains translatable.
- The Tour post meta, cruise itinerary rows, term names/slugs, published Package Rates, Fluent Form 6 and WooCommerce payment settings are untouched.
- No browser-authenticated GUI Save/Readback was claimed. The server tests, syntax checks, XML validation and WP-CLI readbacks passed.
- Backups, dry-runs and evidence on VPS: `/opt/mad4b-package-checkpoints/2026-11-22-program-label-wpml/`.

## Deployment and rollback

Changes deployed selectively on both live companies; branch is PR #1046 (`feature/itinerary-overnight-native-stages-20261122`), pending release approval. The live state does not imply Git merge or green CI.

For rollback, reconcile intervening edits, restore only changed PHP/XML file preimages, and reset the two WPML Copy settings to their recorded preimage values. Restore translated term parents from the saved JSON dry-run if justified; never copy source term names onto translated terms or replace genuine translations.
