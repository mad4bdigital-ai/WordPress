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


## Original multi-type Destination Elementor integration — 2026-11-13

**Owner:** native `destinations` taxonomy, JetEngine term meta and published Elementor "Destination Term Archive Content" documents. This is an original-document migration, not a parallel Elementor template, destination query, HTML widget or new runtime provider.

### Layout and native data contract

- The original Elementor content template still renders its existing About description, Header, map, native recommenders, taxonomy relationships, FAQ and company/WPML settings.
- The insertion point is the native JetEngine `jet-listing-dynamic-field` widget with `dynamic_field_post_object=description`. One native Elementor container is inserted immediately after this widget. No sibling element is replaced.
- The new section uses JetEngine Dynamic Visibility (`exists`, context `current_listing`) to show **only populated** term meta: `why_visit`, `highlights` (native JetEngine Dynamic Repeater), `best_time_to_visit`, `travel_tips`, `internal_links`.
- Long editorial text is in three *original Elementor Nested Accordion* widgets, collapsed by default. Highlights remain visible. On an English Giza Governorate mobile fixture, height decreased from roughly 2085px in the initial fully expanded prototype to about 566px collapsed. On Great Sphinx Attraction, no empty enrichment section is emitted.
- The existing native Google Maps widget now reads `google_location` and hides when the field is absent. The field stores coordinates as a location string (e.g. latitude, longitude). The native map iframe retains its existing lazy loading. A legacy Spanish `destination` singular taxonomy reference was normalized to `destinations` in the map tag only, preserving other company-specific settings.
- The existing Destination Header still owns `featured_image`, `image_gellery` and video, and the FAQ remains in its existing content mount. No duplicate gallery, FAQ, map query or checkout logic is added.
- The field contract responds to real `destination_type` and content coverage rather than inventing separate templates per Continent/Country/Governorate/City/Area/Attraction. The preexisting Elementor/JetEngine hierarchy, WPML relations and query policies continue to select the eligible terms.

### WPML / site-preserving reconciliation

- Inspected original content template family via WPML translation group, not runtime template IDs. Currently present: EN/ES/IT/AR on Tours.dev, EN/ES on Wayfatours. FR/DE dedicated *content* translations are not in this current family; neither has been fabricated or claimed complete. The contract has localized strings for future approved native originals in those languages.
- Changes were applied directly to the six existing Elementor document records (four source, two Wayfatours), with per-document SHA256 preflight, serialized JSON validation, targeted Elementor CSS invalidation and before-state backups outside the public WordPress directory. Every write had an independent readback. No term data or statuses were changed.
- `config/elementor/destination-native-template-contract-v1.json` contains one canonical native section with locale labels. `scripts/migrations/destination-native-template-reconcile-v1.php` discovers the original content template family from WPML dynamically. Its default is read-only dry-run. For a future authorized apply, the operator explicitly sets `MAD4B_DEST_TEMPLATE_APPLY=yes` and `MAD4B_DEST_TEMPLATE_BACKUP` to a writable non-public directory, plus `MAD4B_DEST_TEMPLATE_CONTRACT` to the installed JSON. It performs document-level CAS and stops on unexpected Elementor structures; never overwrites a section already present.
- Reconciler dry-run was tested against both live sites after the direct updates: Tours.dev 4/4 documents and Wayfatours 2/2 documents were already compatible, with zero required writes.

### Quick Preview reuse and regression

- `tour-destination-quick-preview.js` now excludes fields inside the original enrichment section from its About-content discovery, preventing `why_visit` from replacing the actual description if it is longer.
- When an original rich section exists, the same rendered Elementor DOM is moved into the informational modal below About; no second field query, duplicate data mapping or second widget implementation. The actual Nested Accordion remains interactive in the iframe. Native extra/article links are intentionally suppressed in this informational modal (but remain on the original Destination page); the Full destination page action stays available.
- Observed Wayfatours Giza Tour → Split → Governorates → Giza: native Quick Preview shows Hero + About + Insights on desktop 1440px and mobile 390px; all three accordion headers and four destination highlight rows appear, accordion expands, modal closes, Tour URL and split selection persist, with no observed JS page error or horizontal overflow.
- `scripts/qa/tour-destination-preview-browser.py --url <tour-url> --modes split --split-level state_governorate --width 390 --disable-quic` tests this state; `scripts/qa/destination-native-template-browser.py --url <destination-url> --expect rich|basic` covers full native templates.

### Tested destination types and remaining gates

- Direct source HTTP checks: Continent Africa, Country Egypt, Governorate Aswan, City Abu Dhabi, Zone Aswan East Bank and Attraction Abu Simbel Temples all responded 200 without visible PHP Fatal messages. Only the content-rich Country and Governorate rendered extra sections; the other four remained basic, without empty widgets.
- Source Spanish/Italian Giza and Wayfatours Spanish Giza returned 200 and did not render empty insights. No claim of a completed in-browser Arabic or new dedicated FR/DE archive acceptance test.
- **Separate work:** editorial coverage remains low for rich meta fields; legacy `destination_public_status` differs from the canonical `is_public` eligibility contract in some terms. This migration does not change either status and is not a solution for missing translations/media governance.
- **Release status:** live templates and GitHub source are updated, but PR #813 remains Draft/unmerged and no independent original publisher/central signed release receipt is claimed. Follow the normal release gate before broader company rollout.


## Brand Kit-driven Destination Accordion borders — 2026-11-13

**Problem:** the newly inserted native Elementor Nested Accordion titles inherited Elementor's squared gray default borders, while the sibling Destination Highlights and Further Reading native containers used 1px/10px rounded cards.

**Resolution inside the original Elementor widget/template settings, not another frontend CSS layer:**

- Native Accordion `accordion_border_{normal,hover,active}` settings now consistently use a **1px solid** border; `accordion_border_radius` is **10px**. The nested content panel gets its own native Elementor Container `border_radius` (bottom 10px) to avoid square corners on expand.
- Original Highlights and Further Reading containers also use a **1px / 10px** surface outline.
- Elementor `__globals__` binds the styles to **Kit 60050** Global Colors already present on both current sites: `62e8100` (Cold bg = subtle border), `3a5c55e` (Silver = surface), `80789f7` (Warm bg = hover surface), plus the built-in `primary`, `secondary` and `accent` roles. Values are resolved by Elementor at render time; changing the Kit token changes the derived border.
- The active/hover outline uses Kit Secondary; there are no new Kit colors, global CSS overrides or static company-name selectors.
- Six existing WPML original content templates were adjusted with per-document optimistic CAS, nonpublic backups and Elementor post-CSS invalidation: Tours.dev EN/ES/IT/AR, Wayfatours EN/ES.
- The declarative original template contract now stores the Kit style and role references. Its existing WP-CLI reconciler validates the required Global Color IDs in the active company's Kit **before** applying; it patches existing original widget style keys without replacing their translated content. Missing token -> fail-closed, not an unbranded fallback. Post-deployment dry-run on both sites: **6/6 templates present, 0 style changes pending**.
- Only Destination page generation keys were rotated (Tours.dev 853, Wayfatours 536). No global cache purge.
- Browser acceptance: Wayfatours desktop 1440px/mobile 390px and Tours.dev mobile 390px verified the Accordion/Highlight borders both compute to `rgb(217,209,202)`, 1px, 10px; on open, border uses `rgb(32,76,80)`, the lower content-panel corners remain 10px. No horizontal overflow. Existing Giza Quick Preview mobile (Split→Governorates) stayed functional with 3 native accordions and no page-level JS exceptions.
- Regression `scripts/qa/destination-native-template-browser.py` checks consistent widths, radii, card/accordion border colors, Kit role availability and opened content radius.

**Scope:** this fixes original Destination Insights cards, not every Elementor accordion sitewide. Other companies with nonmatching Kit custom token IDs must provide a compatible semantic-token mapping rather than inheriting Wayfatours' custom IDs blindly. The PR is Draft/unmerged and does not itself grant independent release authority.


## Original-elementor-first Quick Preview: single source for content and style — Nov 13, 2026

**Problem and root cause:** the prior Quick Preview copied the JetEngine About text into a custom section and **moved** the original Destination Insights container out of its Elementor document wrapper. The Kit style generated by Elementor is scoped through the original `.elementor[data-elementor-id]` document ancestor; after the move, Giza accordion borders and card typography differed from the full Destination page.

**Permanent repair (both existing sites, no new Elementor document):**
- Keep the **complete native Elementor Destination content-module root in the iframe**. Use the same browser-loaded original Destination Term URL. No separate Term query, additional content API, cloned description widget, new JetEngine meta, alternate HTML widget, or duplicated CSS contract.
- Locate the existing long description Dynamic Field and its original Heading in the same Elementor narrative column, validate their shared parent, and leave both nodes in place. Preserve the original `destination-native-insights` **inside that same Elementor document**; never detach/move it.
- Inside the same-origin informational iframe only, hide the navigation, inquiry, sidebar and cross-selling siblings. A native field without an eligible Insights section stays **Hero + About**, while a rich term stays **Hero + About + Insights**. Unknown markup fails closed to **Hero-only** rather than exposing unwanted sections.
- The floating parent `<dialog>`, lazy iframe load, same-origin URL allowlist, keyboard Escape, full destination page link, lightbox handling and Journey mode/anchor retention remain unchanged.
- Remove old popup-only `.mad4b-destination-preview-about` CSS and generic `.elementor-location-archive h1/h2` overrides. The remaining preview CSS controls the dialog layout and which noninformational blocks are visible; all native content fonts, borders, fills and breakpoints come directly from the **original Elementor template + Brand Kit**.
- Actual mobile (390px) *computed-style comparison* of original Wayfatours Giza and its Giza Tour → Split → Governorates → Popup produced identical About-heading typography (24.1163px), accordion title typography (14.592px), card background (RGB 251,251,251), outline (RGB 217,209,202), border thickness (1px) and radius (10px), without iframe or parent horizontal overflow. Active state still uses Kit Secondary.
- Tour Preview browser regression now asserts no cloned About, native document root retention, Insights staying under the **same template ancestor** as About, Kit border parity, no visible forms, and Tour URL/mode preservation.
- Source Tours.dev Luxor mobile tested as Hero + native About and Wayfatours EN/ES mobile tested (Spanish lacked optional Insights and correctly stayed native About). Wayfatours Explorer/Split/Story all retained working native previews. Backups and CAS checks were used for both asset changes; **stored Elementor templates and Term fields were not rewritten in this fix**.
- Original Destination live page and Quick Preview now have **one source of truth for widgets and styling**; the iframe remains a presentation surface rather than a copied layout.

**Deployment note:** the fix was applied to current Tours.dev and Wayfatours plugin assets, then recorded in this GitHub branch. PR #813 remains Draft/unmerged. This does not create a signed Original release receipt or authorize a different company rollout.


## Complete evidence-safe Highlight linking and compact Journey controls — 2026-11-14

**Problem:** The Journey presentation linked only destination terms whose *exact current-language name* appeared in Highlight text, leaving Giza Pyramids unlinked despite the editorial description naming the Great Pyramids. Elementor's generic button styles also inflated the Split/Explorer/Story geographic breadcrumb controls.

**Native data and linking contract:**
- Continue to use `MAD4B_Tour_Highlights_V1::related_places` as the sole eligible Destination provider, enforcing each company's native term-discovery eligibility. No second query/listing source, autogenerated visits, or separate destination taxonomy.
- The original `related_links()` now accepts bounded exact term names, distinctive geographic title variants whose parent is a verified term and whose noun is unique among the Tour's eligible attractions, or editor-curated language aliases. Every rendered link has a verified `data-tour-term-id`, `data-tour-evidence=mentioned` and match provenance (`exact`, `qualified`, `parent-head`, or `curated-alias`). There is no arbitrary three-link cap.
- Do **not** infer Valley Temple of Khafre from the isolated word “Khafre.” Parent-head/curated matching must resolve to a unique approved term.
- Original JetEngine `destinations` taxonomy schema now has a 19th native meta field **Tour Highlight — Alternative Destination Names**, implemented as `tour_highlight_aliases` Repeater with `tour_lang` (six locale options) and `tour_alias` (text). Existing 18 fields were preserved byte-for-byte in order. The field is per translated WPML term, not an English ID copied across locales.
- Current Wayfatours verified example: English terms Giza Pyramids (2517) and Great Sphinx (2518); Spanish translated terms Pirámides de Giza (2662) and Gran Esfinge de Giza (2664). Only explicit editorial aliases were seeded for those four terms, using guarded unique term-meta insert with baseline backups. Broader editorial term coverage remains future content work.
- The existing Journey UI uses the localized original server-rendered label when converting approved links into visual mini cards; the original href and Quick Preview integration stay in place.

**UI and acceptance:**
- One shared mode renderer for Explorer, Story and Split, each preserving its own layout. Breadcrumb/ancestor buttons are compact native Kit control surfaces and no longer inherit Elementor's oversized default typography. On Wayfatours Giza: breadcrumbs computed at **10px / 25px** on 390px mobile and **11px / 26px** on 1440px desktop. Taxonomy level controls are 34px mobile/32px desktop. Story's action is a short localized label; measured 125×44px within a 344px Highlight card on mobile.
- Related visual mini cards are grouped compactly inside Highlights; the native destination cards remain the gallery source. No redundant white wrapper or `background-image:none` override was reintroduced.
- New regression `scripts/qa/tour-journey-compact-links-browser.py` validates source-bound Term IDs, forbidden inferences, all three modes, responsive typography/height, pointer count and no overflow.
- Passing live acceptance: Wayfatours EN Giza **390px/1440px** and Wayfatours ES Giza **390px**, all three modes, two actual referenced places, Story six pointers. The source Tours.dev Nile cruise test passed at 390px without fabricating a destination match. Giza Governorate Quick Preview test also passed with original Elementor Insights and Kit borders.
- HTTP checks after scoped cache invalidation: EN/ES first Highlight renders two verified links, Spanish using its own WPML Term IDs. No PHP Fatal in tested documents.
- Reversible migration scripts: `scripts/migrations/jetengine-destination-highlight-aliases-cas.php` and `scripts/migrations/destination-highlight-aliases-terms-cas.php`. Both are dry-run by default, require nonpublic baseline backups and explicit approval/expected hashes for writes.
- PR #813 remains Draft/unmerged; no independently signed release admission is claimed. Existing unrelated full-PR Production Readiness, Site Neutrality and Admin Performance CI failures require separate remediation.


## Evidence-only multilingual Destination aliases — verified backfill (2026-11-14)

### Source and eligibility coverage

Audited the **original eligible Destination list** via `MAD4B_Tour_Highlights_V1::related_places`, the real JetEngine Highlight renderer and WPML's tour locale, without building a parallel taxonomy:

| Site | Published tours with Highlights scanned | Highlight cards | Editorial aliases appended | Native Destination terms changed |
| --- | ---: | ---: | ---: | ---: |
| Wayfatours Production | 84 | 528 | 15 | 14 |
| Tours.dev source | 216 | 1,231 | 30 | 27 |

The **first** verified batch was 5 terms on Wayfatours and 8 on source (one new name per term). The **second**, evidence-gated additive batch was 10 names on 9 Wayfatours terms and 22 names on 20 source terms. One source term already had a first-batch name; existing rows were preserved and extended, not replaced.

Every second-batch row has a published Tour ID, exact 1-based Highlight index, matching language, and a verbatim phrase in the published Highlight. The application checked its link to the **original eligible WPML term ID**, exact Term name, source-content evidence and uniqueness, persisted a nonpublic baseline and used per-term serialized-value CAS for edits. No taxonomy relationship, tour text, stored Elementor document or booking/pricing data was edited. No single-word generic landmark guesses were inserted.

Verified editorial examples include **Khan El-Khalili**, **El Moez Street**, **Cairo Citadel**, Spanish **Jan el Jalili**, **Tunis Village**, **Magic Lake**, **Wadi El Hitan**, and Italian **Via Al Muizz**, **Sfinge di Giza**, **Grand Egyptian Museum**, and **Montagna Al-Madoura**. Terms already assigned to a Tour but not supported by a concrete Highlight remain tour context, not an invented confirmed visit.

### Readback and acceptance

- Independent meta readback: **30/30** added alias rows on **27 source terms** and **15/15** on **14 Wayfatours terms**; original JetEngine native 19-field schema left intact.
- Wayfatours full source-bound audit went from **210** verified links before the backfill to **228** afterward (**+18**). The link-level diff did not remove previously linked destinations.
- Tours.dev gained **9** source-bound links in batch 1. Its later full audit also reflected simultaneous changes in eligible place coverage (from 485 to 613) and other source code outside this backfill; **do not attribute the aggregate reported link increase to alias edits**. Specifically scoped audits isolated new links on the affected Tours. Source-side concurrent code was **not overwritten**.
- 9 live tour-page HTML smoke checks (EN, ES, IT; both sites) passed: all expected term IDs present in rendered Highlight links, intended `curated-alias` provenance, no PHP Fatal.
- Only the **16 affected source pages** and **8 affected Wayfatours pages** had their existing HTML generation keys rotated for batch 2, with no global cache purge. Every target path was validated against the published permalink and existing generation key.
- Remaining exceptions are intentionally review-only: generic mentions like *Corniche* without a sufficiently distinguishing confirmed label, Khafre appearing without an independent Valley Temple visit, and cross-locale taxonomy/eligibility shifts in long combined test Tours. They are **not** repaired by adding speculative aliases.

### Permanent controlled workflow

- `scripts/qa/destination-alias-coverage-scan.php` — original WordPress/JetEngine+WPML read-only audit.
- `scripts/migrations/verified-alias-merge-cas.php` — generic evidence-validated **dry-run by default** updater; required nonpublic backup and input SHA on apply; handles new and existing repeater rows.
- Earlier schema and term insert tools remain available, but should not be used to overwrite populated alias repeaters.
- Data backfill manifests and sensitive operational backups remain on the authorized VPS, not hardcoded into runtime matchers. A source canonical release must separately reconcile concurrent source-only PHP changes and pass the full PR checks.

**Status:** live data and representative view checks verified; PR #813 remains a Draft/unmerged change set without a signed Original release receipt.


## WPML and Destination eligibility separation — 2026-11-14

**Root cause and containment.** A mixed-language audit can ask WordPress for Spanish Tour 57563 while the mutable WPML request language remains English. The unguarded `wp_get_object_terms` then returned English translations belonging to that tour's WPML group, which contaminated combined audit results. The original Tour→Destination term relationships in the database were independently verified to use their correct per-language term taxonomy IDs. No WPML assignment mutation or translation rewrite was required.

**Original runtime correction.** In `MAD4B_Tour_Highlights_V1::related_places()`, an independent WordPress/WPML element-language assertion now compares every returned Destination term's WPML language against the Tour's recorded translation language. The request-local cache key includes the Tour ID, recorded Tour language and active request language. Incorrect runtime context fails closed (zero places), whereas correct EN/ES/IT contexts preserve the original 33/31/31 terms for the long 8-day Tour. For the newer source-only read-only governorate discovery, Term, ancestor and inferred governorate language must also match before inclusion. No taxonomy relationships or content were rewritten.

**Provenance contract.** The source JetEngine/Elementor renderer retains one approved registry but labels distinct sources:

- `mentioned`: a tour-linked attraction explicitly and uniquely mentioned in a Highlight;
- `itinerary`: another verified attraction listed in the same authored Itinerary stop and allowed by the original bounded companion rule;
- `editorial-context`: an editor-associated (and still eligible) term, not a claim that a visit is included;
- `geographic-context`: a city, zone, country or governorate providing location hierarchy, not a confirmed visit.

The original Journey JS and mini preview labels preserve these evidence types in EN/ES/FR/DE/IT/AR. Geographic context is only embedded beside a real attraction reference within the SAME Highlight; generic service text such as “Islamic Cairo tour” or a stand-alone destination region is not converted into a visit or a Story pointer. Broader Tour context remains discoverable in Explorer and the original native JetEngine listing; native Elementor templates and term relations remain authoritative.

**Live verification.** PHP regression cases passed on source and Wayfatours: wrong context for Spanish Tour 57563 yields zero term leaks; correct ES restores 31; all tested assigned and inferred terms match the Tour's WPML language. Native Illustrator / WordPress semantic evidence tests confirmed Cairo Citadel + Muhammad Ali Mosque as mentioned, Cairo as geographic context, and blank destination links on generic service items. Source Giza sample shows Great Pyramids + Great Sphinx plus Valley Temple only when independently verified in the original same-stop Itinerary; Wayfatours links the two stated attractions without inventing the temple. Live HTML passed without PHP fatal, including a case with only geographic context. Both 390px and 1440px browser checks passed with JS errors=0 and no horizontal overflow.

**Stable independent audit.** `scripts/qa/destination-alias-coverage-scan.php` now contains an independent per-term WPML language readback and separate counts by provenance, plus live PHP SHA-256 and eligibility/Highlight-content fingerprints. Reports with differing eligibility hashes must never be interpreted as pure alias-related gains. Current accepted data:

| Contract | Tours | Highlight cards | Mentioned attraction links | Itinerary links | Geographic context links | Locale leaks |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Wayfatours | 84 | 528 | 119 | 0 | 44 | **0** |
| Tours.dev | 216 | 1,231 | 183 | 8 | 71 | **0** |

The link count intentionally decreased after removing geography-only service-item link associations; this is a semantic correction, not deleted Destination relationships. Tours.dev has an additional **read-only exact-geography inference**, evidenced and counted separately; Wayfatours currently exposes assigned terms only. Do not silently deploy source-only query discovery to a company that has not passed its native Query Builder contract.

**Regression gates:** `scripts/qa/wpml-journey-evidence-contract.php` and `scripts/qa/wpml-journey-evidence-browser.py` test WordPress language isolation, native link evidence, viewport and JS safety. After canary tests, only existing Tour HTML generation keys were rotated (**246 source, 90 Wayfatours**) without a global object/cache purge. Changes are staged in **PR #813 (Draft)**; no signed Original release receipt or full CI approval is claimed.

## WPML source-of-truth and evidence-tier regression — 2026-11-14

The native original Tour Highlights / Elementor / JetEngine flow now applies a **Tour-derived WPML language guard** on both Tours.dev and Wayfatours. Terms with language different from the queried Tour are dropped, even if global WPML state changed. Deliberately querying the ES Tour in EN context returned zero leaked Terms, while re-entering ES returned 31 eligible Terms. Existing EN/ES/IT assigned terms remained unchanged; no translation relationship was edited.

**Evidence is not equivalent to a promised visit.** The native source distinguishes a named attraction in the Highlight (mentioned), a verified co-stop in the original Itinerary (itinerary), editorial context selected explicitly (editorial-context), and a geographic parent/area (geographic-context). Generic service/pickup copy must not create a place link; geographic context accompanies a definite landmark only. Story and Explorer do not count geographic-only context as a visit. Native Term IDs, eligibility policy, company template, and Quick Preview remain authoritative.

**Later source reconciliation:** Tours.dev subsequently gained (and this PR now preserves) stable Highlight item keys on save and reorder, optional/excluded-visit language checks, scoped native Query 182 instrumentation (only in the real Journey listing), external cacheable JS and accessible native sibling selection buttons. We compared the branch against live files and updated the four original PHP/JS files without overwriting newer source. Wayfatours deliberately retains its company-owned production features and remains on its older stable-key/activation behavior; this is not a claim of full feature parity.

**Read-only acceptance:**
- WP-CLI contract passed on both sites, including EN/ES/IT long tours and wrong-locale fail-closed checks.
- Full audit with distinct source SHA, eligibility and editorial fingerprints: Tours.dev 216 tours, 1,814 Highlights, zero language mismatches; Wayfatours 84 tours, 528 Highlights, zero language mismatches. Differences in aggregate eligibility are not attributed to alias edits without matching source and eligibility fingerprints.
- Live browser acceptance at 390px and 1440px on both sites: Cairo Citadel and Muhammad Ali Mosque remain genuine links, Cairo appears as Geographic context, unrelated generic service Highlight remains unlinked, no JS errors or horizontal overflow.
- Source long ES/IT tours at 390px: ten unique Highlight item keys each; verified source-bound links, no overflow or JS errors.
- Manual read-only HTML-injected original renderer modes at 390px: Split 8 Highlights, Explorer 8 Highlights, Story 8 pointers (4 directly linked); all no overflow and correct provenance.
- New scripts/qa/journey-certainty-stable-key-contract.php passed on Tours.dev with optional/excluded EN/ES/FR fixtures and stable identities after reordering identical-text duplicates.

**CI:** Site-neutral's intended exact-file tourism-domain review was recorded for the two existing first-party Highlights PHP modules in tests/site-neutrality-policy.json (authorizing=false); Site-neutral CI subsequently passed. Production Readiness separately reports FAIL_UNCOVERED_CHANGES pending substantive capability/coverage review; unrelated Admin Performance and Control Plane Package failures are not waived. PR #813 remains Draft/unmerged; no signed Original release receipt is claimed.
