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

- `scripts/migrations/tour-journey-role-migration.php`: role-based Elementor discovery/migration, site-identity check, CAS updates, and `TOUR_JOURNEY_ACTION=preflight|apply|verify`. Snapshot before writing.
- `scripts/qa/tour-journey-acceptance.php`: read-only role, native destination listing, translation and WPML check. Usage: `TOUR_JOURNEY_SITE=https://your-site.example wp eval-file scripts/qa/tour-journey-acceptance.php --allow-root`.

**Observed results:** Wayfatours 3/3 native Elementor Tour documents; Tours.dev 7/7, with one combined section, one Highlights field, one native Elementor destination template per Tour document and five categorized groups in each related template. Role migration was idempotent. Live browser QA on Wayfatours EN+ES at 1440px and 390px returned HTTP 200 and found four working presentation modes and three switchable destination tabs without reported JS errors or horizontal page overflow. Tours.dev published five-day Nile cruise was HTTP 200 with two visible destination groups inside the combined section.

**Still separate from repository review:** the PR is a draft; no independent signed release or PR merge has been approved. Visual/browser QA of every WPML language and individual Tour subtype is not complete. A QA draft requiring an authenticated preview session cannot be validated through an anonymous public preview URL.

## Final Elementor/WPML parity and browser acceptance (November 13, 2026)

- The single canonical Elementor migration is `scripts/migrations/tour-journey-role-migration.php`; a redundant earlier draft was deleted from the PR. Native Elementor **widgets** need each role in both `css_classes` (data contract) and `_css_classes` (actual Advanced CSS Classes in rendered HTML). Inserting the role in only `css_classes` is insufficient.
- This was verified via CAS migration and readback on 7 Tour templates at Tours.dev (21 widget Advanced roles) and 3 at Wayfatours (9 widget Advanced roles); a second run reported zero changes. Source backups: `/opt/mad4b-backups/tour-highlights-v1/widget-advanced-roles-20261113/`.
- The canonical read-only structural test is `scripts/qa/tour-journey-acceptance.php`; it checks actual rendered roles and WPML's **resolved** Elementor destination template by locale. WPML may store the English reference but correctly render a localized template; do not rewrite it purely because the raw ID is the English ID. Current acceptance: 7/7 on Tours.dev, 3/3 on Wayfatours.
- Source was missing the French and German translations of destination template `48366`. Added French template `70002` (native city listing `68818`) and German template `70007` (native city listing `68821`), each linked to the existing WPML translation group. They use independent translation editor mode. Keep backups under `/opt/mad4b-backups/tour-highlights-v1/wpml-destinations-fr-de-20261113/`.
- The resolver's `localize_native_grid` callback applies WPML's object ID mapping **only while rendering a verified native destination-group container**. Outside the group it must leave unrelated JetEngine listing references untouched. FR `45927 → 68818`, DE `45927 → 68821`; both cases and the negative (outside-group) case were tested.
- Browser acceptance is now versioned as `scripts/qa/tour-journey-browser.py`. A 390px responsive matrix on an actual published Tour at Tours.dev passed for EN, AR, FR, DE, ES and IT. EN+ES on Wayfatours passed at 390px and 1440px. Every run exercised all four presentation modes, native destination tabs, horizontal overflow checks and JS page errors.
- For the source ES URL, headless Chromium intermittently encountered `ERR_NETWORK_CHANGED` with the default network stack. Repeating with Playwright Chromium's `--disable-quic` passed HTTP 200 and complete UI assertions; this is not a claim that the server root cause has been resolved.
- The test Tour #69715 requires an authorized preview session; it is not an anonymous public verification URL. A published 5-day Nile Cruise was used instead.
- Cold TTFB measured directly on source after cache rotation was ~10.9s (FR) and ~8.6s (DE). Investigate this as a separate performance issue; do not conflate rendering correctness with improved server latency.
- The historical `tour_highlights_content` copy in some translated Tours remains English despite translated interface labels. Editorial localization and approval of Smart Highlights suggestions are **not** automatic. No production release authorization, PR merge or signed publisher receipt is implied by runtime QA.

## Split V3: destination-type hierarchy (November 13, 2026)

**Scope:** This is a separate progressive enhancement of the existing native Elementor Tour Journey component. Keep Explorer and Story on their existing V2 renderer. Do not create a second JetEngine query, destination CPT, parallel Listing, or duplicate destination card.

- **Data contract:** `MAD4B_Tour_Highlights_V1::related_places()` returns only company-eligible `destinations` terms linked to the current Tour with `id`, `parent`, `label`, `kind` (the `destination_type` term-meta value) and canonical language-specific term `url`. The Tour Elementor section carries a JSON-escaped `data-tour-destinations` attribute. The JSON is not a claim that each term is an included itinerary visit.
- **Native classification:** Split joins current locale term URLs to the already rendered JetEngine cards. It groups by `destination_type`, orders available geography levels, and uses the actual taxonomy `parent` chain for breadcrumbs. Tabs are displayed only for levels with visible term cards; unavailable types are not fabricated.
- **Highlight relationship:** If a Highlight references an eligible location, its group contains that term, the verified sibling attraction terms sharing its actual taxonomy parent, and eligible linked ancestors. Mentioned place versus associated Tour context is retained independently. Without a referenced place, the panel offers the Tour's eligible geographic context and labels it as such, without inferring a stop.
- **One destination card per term:** The right-hand Split panel reuses JetEngine's original cards. The duplicated inline photo tile is suppressed only in Split and replaced with a single count + explore group strip in the selected Highlight. Explorer and Story retain their original inline place visuals.
- **Responsive layout:** Desktop presents highlighted items beside the native destination panel and a directional state. On narrow viewports the highlighted item stacks above its destination panel; no squeezed inline photo. Selector labels remain concise per language rather than repeating the full Tour title. Different levels show category-specific counts; breadcrumbs update whenever the user chooses a different level.
- **Files:** `assets/tour-journey-split-v3.js` and `assets/tour-journey-split-v3.css` remain separate from `assets/tour-journey-ui.js` and the core component stylesheet. The presentation adapter attaches these two scoped files after the base Journey assets. No third-party animation library or new AJAX endpoint is introduced.
- **Evidence:** The V2 adapter preserves `data-tour-evidence` when replacing the eligible source term link with a visual tile; the Split renderer reads it to avoid upgrading merely associated places to asserted mentions.

### Acceptance and rollout

- Run the read-only browser contract: `python scripts/qa/tour-journey-split-browser.py --url https://your-site.example/tours-and-activities/example/ --width 390 --disable-quic`. The QUIC flag is a test-environment workaround, not a claimed production network fix.
- WordPress syntax and browser checks passed on Wayfatours EN+ES at 390px/1440px, plus a 320px mobile check. The Giza Tour's verified taxonomy produces `Egypt → Giza → Attractions (3)`, with one mentioned attraction and two related attractions, and `Countries (1)` / `Governorates (1)` navigation. No duplicated JetEngine listing, page-wide horizontal overflow, or JS page errors were observed.
- Tours.dev published Nile cruise was tested in EN/FR/DE/ES/IT/AR on mobile after the existing Elementor entrance animation became visible. Correct locale labels, taxonomy hierarchy, mode switching back to Explorer, and no page overflow or JS errors were observed. Tour geography only has eligible Country/Governorate levels in this fixture, so Attraction-level behavior was verified on Wayfatours rather than assumed on Tours.dev.
- The site-specific FastCGI version mechanism was advanced for 90 Wayfatours and 246 Tours.dev exact Tour page paths, not globally. The canonical `/opt/mad4b/operations/tour-highlights-v1/` sources were reconciled with the live files and the superseded semantic experiment preserved in `experimental/` as non-deployed review material.
- Runtime parity and browser QA do not mean that PR #813 has been merged or independently signed as a formal release. Further subjective visual review of the component on additional tour subtypes remains valid before production rollout beyond current sites.


## Native Destination Quick Preview — November 13, 2026

**Purpose:** Clicking an eligible JetEngine destination card in Explorer, Split or Story opens an informational preview without replacing the Tour page. The current Highlight, category, browser URL and form selection remain unchanged.

### Reuse and boundaries

- Reuses the existing Elementor destination taxonomy page as rendered in a same-origin, on-click iframe inside a native keyboard-accessible dialog, following the Extra Options Quick Preview interaction model.
- It is **not** the Extra Options selection handler: it has no postMessage-based selection, no Fluent Form 6 mutation, no booking CTA, no new WordPress query and no tour price functionality. Extra's existing code and files remain unchanged.
- The existing Tour Journey section supplies a filtered destination-term list validated by company taxonomy eligibility. The click interceptor only permits an exact same-origin pathname in this list. Modified clicks retain browser behavior; the toolbar provides an explicit full-page link.
- The destination frame resolves the real Elementor document tree by native wrappers, a H1 inside the hero and an eligible JetEngine Dynamic Field with descriptive text near a heading. It does **not** hardcode template IDs, element IDs, Elementor archive wrapper class names or the position of the About block. This addresses a verified wrapper class difference between Wayfatours and Tours.dev.
- Its display is **Hero + About** when the native descriptive field is available. An otherwise eligible term can show **Hero only** if no qualifying description exists. Non-content Elementor modules and forms are hidden **inside the iframe only**. Stored Elementor templates and normal destination URLs stay unchanged.
- The CSS hides header, footer, cookie overlay and inquiry buttons only inside the iframe. The site's actual cookie consent mechanism is unchanged; hiding a second visual overlay does not grant optional consent. A nested Elementor gallery popup stays inside the frame.
- Only one destination preview is open at a time. Escape works with keyboard focus inside the iframe except while a nested gallery is open. Closing returns focus to the original destination card. The script does not initialize another destination popup in an Extra Quick Preview iframe.
- The frame loads only on the user's click; no extra destination page is requested in the Tour's initial rendering path.

### Source files and acceptance checks

- assets/tour-destination-quick-preview.js — approved same-origin link interception, native Elementor semantic content discovery, modal lifecycle, focus and gallery isolation.
- assets/tour-destination-quick-preview.css — responsive dialog and iframe-only informational content rules.
- includes/class-mad4b-scp-tour-highlights-presentation-v1.php — enqueues the scoped assets on Tour Single and authorized Elementor preview.
- scripts/qa/tour-destination-preview-browser.py — checks native Hero, JetEngine About or Hero-only fallback, no visible forms, same-origin term link, no page navigation, no horizontal overflow, mode preservation and closing. Example: python scripts/qa/tour-destination-preview-browser.py --url https://example.com/tours-and-activities/example/ --width 390 --modes all --disable-quic

**Observed tests:** Wayfatours published Giza tour EN (desktop 1440px and mobile 390px), ES mobile 390px, and all three Journey modes in EN; Tours.dev Nile cruise EN/FR mobile 390px, with native Luxor/Louxor destination heading and About. No page-level JavaScript exceptions or horizontal overflow observed in those tested cases. This does not claim exhaustive validation of every language/term subtype or a signed Production release.

**Deployment:** Live assets were applied on the two current sites with baseline preflight and backups, not by changing Elementor document data. The GitHub branch remains a Draft PR pending its normal merge and release authority.
