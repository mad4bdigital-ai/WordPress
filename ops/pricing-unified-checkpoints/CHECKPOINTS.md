## JetEngine Unified Workspace Shell — 20 November 2026
- Root cause of disconnected-looking settings: JetEngine renders a second native cx-section header/container following the MAD4B workspace, while product modules (Accommodation and Transportation) bring separate details outer cards and distinct accordion CSS. These are separate DOM and Save owners by design.
- A presentation-only MU layer mad4b-jetengine-workspace-shell-v1.php plus assets/mad4b-jetengine-workspace-shell-v1.js and .css now unifies the Grouped JetEngine View across supported Options Pages. It does not change native mode, input width, field storage, JS handlers, supplier pricing or Rate authority.
- Native mad4b-ws-content and original mad4b-ws-original remain separate DOM elements but share one visual panel (joined border/radius and zero visual gap). Only the duplicated page-level native cx-section__info is visually hidden; native input labels, repeater values, validation and the original vendor Save remain mounted and working.
- In Product panes, the Pricing Workspace's single service content panel is retained. An extra top-level Accommodation/Transportation accordion box is visually flattened, but each original details/summary and provider editor remains interactive.
- Shared disclosure tokens and chevrons now cover Dimensions, Provider Sources, Supplier Inspector, Accommodation and Transportation main accordions, while nested scope/filters/rates get consistent typography and borders.
- Sidebar adds accessible Company-wide settings / Products & supplier services groups. The latter is inserted only when the original service composer dynamically adds the first product navigation button; no native buttons are replaced.
- Rollback is deleting the standalone MU PHP, JS and CSS files; live original functional modes, pricing-workspace-unified composer, vendor and service modules stay untouched.
- Staging and Wayfatours: PHP syntax, script DOM nav test, authorized enqueue/guest denial, original source revision unchanged, identical 3-file SHA, public HTTP 200. service-activation is tested as a second JetEngine Options Page on both sites. Browser-authenticated visual/keyboard/mobile acceptance remains OPEN.
- Guide Costs uses a separate native Global/Term approval panel and the guide-cost-tab-active class, not the Stay/Transport composer. The shell now treats it as a Product pane, preserving the original approval forms but flattening the extra WordPress postbox, restoring a complete outer border and grouping the Guide navigation even when mounted before Stay/Transport.
- This Guide extension passed identical CSS/JS hash, private admin source checks and delayed service navigation DOM acceptance on both sites. No Guide policy was saved.
- Reconciliation caveat: mad4b-pricing-registry/assets/pricing-workspace-unified.js is present identically on both live sites but not in this branch's tracked source. The MU shell deliberately does not overwrite or vendor-copy this unrelated live module. Its source enrollment requires separate reconciliation.

# MAD4B Unified Pricing & JetEngine UX — Release Checkpoints
Snapshot date: 20 November 2026. Primary source: Tours.dev staging; target: Wayfatours production. This branch is stacked on PR #965.

## Financial and data guardrails
- Never activate Checkout or publish historic Wayfa Tour Rates without certified children, accommodation, transport, guide, extras, tax, FX and company margin evidence.
- Tour Types / Package Levels are preview-only markups applied to old selling start_price, NOT supplier cost proof.
- JetEngine CPT/Taxonomy/Options Page definitions remain source of keys, types, native form and save actions. Do not duplicate inputs or clone policies.
- Staging first, original file backup, rollback, tests on both sites and Git SHA parity for every deployed component.
- Never claim active customer price merely from an enabled native draft or preview.

## Integrated checkpoints
| Checkpoint | Scope and acceptance | State |
| --- | --- | --- |
| CP0 | Capture both sites' settings, feature SHA, Git and #965 CI | PASS: identical source preview SHA 384f523d; 5/5 CI #965 |
| CP1 | WPML Package IDs accepted across languages only when original WordPress Tour relation exists; invalid Package refused; native quotes unchanged | PASS: 228 Staging and 477 Wayfa identity checks, 87 translations, 193 foreign IDs rejected, 0 failures. 192/197 price previews unchanged |
| CP2 | Reconcile core JetEngine functional-modes PHP/JS/CSS source vs Wayfa, native Save and dependency contracts; browser regression | SERVER PASS, BROWSER OPEN: 53 pricing/31 activation fields; optional Recipe Registry compatibility; browser Save/repeater/mobile not yet certified |
| CP3 | Native cost provider eligibility, approved hotel/transport/guide/extra receipts and date/currency/passengers | CONTRACT AND SOURCE AUDIT PASS; SUPPLIER DATA OPEN: 134 room receipts editorial only, transport PDF unverified, extras cost unapproved |
| CP4 | Deterministic integer-minor-unit shadow evaluator with markup vs margin separation, fixed rule ordering, duplicate protection; full FX/passenger/provider integration separately gated | SHADOW PASS, COMMERCIAL OPEN: 7 unsafe cases rejected on both sites; actual cost, FX, occupancy/children receipts still missing |
| CP5 | AJAX native entity selector and progressive disclosure of 16 financial fields in 21-field policy repeater; no extra Save or hidden disabled native values | SERVER+DOM PASS, BROWSER OPEN: original input and change events tested, logged-in UI Save/mobile/real repeater markup not yet certified |
| CP6 | Source-owned original JetEngine Options Page Save CAS with HMAC revision of 54 separate options, nonce and DB lock | PRE-SAVE SERVER ACCEPTANCE PASS; authenticated parallel browser Save and other-source-writer review open |
| CP7 | Eight Wayfatours Package Tours, EN/ES, EGP/USD/EUR/GBP, adult vs family, supplier evidence | 128 READ-ONLY CASES PASS with zero false approvals, zero Rates written; 60-row supplier backlog; real costs/FX/children blocked |
| CP8 | Git/GitHub/live SHA, new branch CI, explicit approval, merge and rollback | OPEN: pending browser, cost, transactional writes, full E2E |

## CP1 evidence
- Imported the source preview file from both matching live WordPress MU roots; it was previously absent from the Git feature branch.
- package_for_tour resolves caller WPML term to the actual original Taxonomy relationship and checks canonical term identity, no target guessed from name.
- Test: ops/pricing-unified-checkpoints/tests/verify-wpml-package-preview.php.
- The five pre-existing preview failures remain: 1 disabled Platinum and 4 missing price-base selections on posts 57562/57563; no Tour Rates, prices or orders were mutated.

## CP3 runtime findings
- New MU mad4b-component-cost-contract-v1.php installed on Staging and Wayfatours with identical source SHA; no web route or customer price mutation.
- Schema validates approved supplier-cost role, supplier identity, Tour/date scope, per-unit integer minor amounts, quantity, proof SHA, reviewer and expiry.
- Composer refuses duplicate line IDs, mixed currency without verified FX, rejected or missing receipts, and selling/retail cost substitution.
- Complete synthetic four-kind set returns internal cost only and keeps rate_generation_ready=false, checkout_ready=false, provider reverification required.
- Acceptance: ops/pricing-unified-checkpoints/tests/verify-component-cost-contract.php, passed both sites. Contract ready is not real supplier input readiness.

## CP4 and CP5 evidence (source-backed only)
- CP4: mad4b-price-evaluator-shadow-v1.php, acceptance ops/pricing-unified-checkpoints/tests/verify-price-evaluator-shadow.php. Integer minor-unit cost basis, mutually exclusive markup or margin and single instance per financial stage. Fixed order; synthetic 8,000 EGP cost -> 14,158.80 EGP after configured test rules and 14% test tax. Seven unsafe cases blocked. Neither FX conversion nor child/occupancy/pricing source re-verification implemented or approved.
- CP5: mad4b-pricing-entity-picker-v1.php plus JS lookup, accessible search and results without named form controls or additional save actions. Native number input receives the actual selected term/post ID and input+change events. Taxonomy WPML canonical ID displayed for inspection, but stored ID remains the selected native ID. Native 21-field policy form gets progressive disclosure of advanced fields in visual mode only; non-default overrides start expanded. Source and production hashes matched.
- CP5 tests: verify-entity-picker.php, verify-entity-picker-dom.js, verify-conditional-jetengine-mode.js passed. This **does not certify** that controls appear in a real admin browser: selectors and logged-in native Save still need independent test.

## Supplier Readiness UX relocation (20 Nov 2026)
- Full read-only Supplier Cost Inspector moved from above the editor into Price Sources, inside the Functional Modes workspace but outside the original JetEngine form.
- Overview now includes only a compact readiness summary linking to the Price Sources Inspector.
- Published Tour Preview exposes the canonical selected Tour via root.dataset.mad4bPreviewTourId and a read-only custom event. Changing a Tour clears old inspection evidence; the independent selector is hidden when Tour Preview is available.
- The Tour Preview has a Review supplier costs CTA that opens the same Inspector; no second native Save, form, named input, Rate mutation, or checkout path added.
- Inspector JS depends on the registered native Functional Modes and Published Tours scripts.
- Staging and production shipped with VPS rollback copies. Both passed native script dependency/admin security verification, actual JetEngine form render, source hash parity for four UI files and simulated DOM navigation/selection checks. Public HTTP 200.
- Browser-authenticated click, rendering and mobile/responsive checks remain open.

## 20 November review-only supplier admin UI
- Added an authorized lazy AJAX panel for Supplier Cost Readiness on the existing native Pricing Controls admin page. It does not wrap, replace, or submit the JetEngine form.
- Authorized managers can inspect any selected published Tour, service date, currency (EGP/USD/EUR/GBP), adults, children and infants. Each original component source reports pending proof and accommodation stage-level blockers. The optional Tour edit link is only accepted when same-origin and authorized.
- Files: MU mad4b-package-supplier-admission-v1.php; assets/mad4b-package-supplier-admission-review-v1.js and .css. PHP source and assets deployed to Tours.dev and Wayfatours with backup and read-only tests.
- Server checks verify private AJAX endpoint, authenticated JS/CSS load, guest denial and no change to native Save. JS DOM mock verifies panel is outside original JetEngine form, input/control names never conflict, AJAX request is signed and no second Save is created.
- Tests: tests/verify-package-supplier-admin-ui.php, tests/verify-package-supplier-admin-ui-dom.js. Live authenticated browser click/visual acceptance is still OPEN.
- No supplier costs are approved or created by this UI. Real dates, contract amounts, room/cabin selection, verified guide days, extras eligibility and signed FX/tax remain required.

## Native form server-side rendering verification
- Both sites rendered the real original JetEngine Pricing Controls form via its own page factory, with one form, native Save submit, settings_top, existing 21-field Mode repeater and correct original Package Level Repeater structure.
- Wayfatours has stored Package rows such as shared_package_pricing_rows[item-0][package_term_id]. Tours.dev has the registered Repeater container but no saved Package row; test accepts this legitimate difference rather than inventing a row.
- Verifier: tests/verify-native-jetengine-form-markup.php. Real HTML rendering accepted on both sites with no source option mutation.
- This is not a logged-in two-browser Save, client-side AJAX, or mobile interactive certification.

## CP7 read-only matrix
- Detailed evidence: QA_RESULTS_20261120.md, tests/verify-cp7-readiness-matrix.php.
- Tours.dev: 221 published, 116 assigned package terms all blocked because source preview policy is not configured.
- Wayfatours: 84 published, 197 package relationships, 192 preview_ok, one disabled Platinum, four missing base prices. WPML identity checks pass.
- No Rate status or pricing option changed.
- Authenticated Save and mobile tests, real 8-package commercial end-to-end remain open. Broader regression rerun hit STAGING_WPCLI_BUSY and was not counted as passed.

## CP6 source-aware preflight
- New MU mad4b-pricing-policy-write-preflight-v1.php only inspects native provider state, does not call a writer.
- Pricing Lab Draft state and source Revision checked against the native option store.
- Policy Bindings validates scope, target, references and revision through MAD4B_Hybrid_Pricing_Policies_V1 without binding anything.
- JetEngine Options Page writes fail closed because its native saving hook increments revision but does not enforce an expected version atomically. Checkout activation always blocked.
- Signed admin AJAX (only for authorized pricing managers); no guest route. Acceptance: tests/verify-policy-write-preflight.php on Tours.dev and Wayfatours, no options changed.
- This is NOT a completed transactionally consistent write gateway. Retain native Save UI and original actions for now.

## CP2 runtime findings
- Original Wayfatours module.php does not include the optional Recipe Registry and its styling assets, unlike Tours.dev. The first attempted three-file upgrade **failed** during a WP-CLI asset test and was rolled back to exact original hashes, verified with HTTP 200.
- A source-neutral guard now calls the Recipe Registry only when the class exists, and enqueues optional Recipe field CSS only when available.
- Deployed the source core class, Functional Modes JS/CSS, and optional CSS only after staging acceptance. Production test passed the native field schema, 9 compatible UI Modes and registered dependencies, HTTP 200. Source CSS/JS hashes match both sites.
- Did not replace module.php or install the entire Recipe Registry on production. The browser-authenticated Save and mobile repeater acceptance remains open.

## CP2 source reconciling notes
Functional Modes source PHP and JS in Git match Tours.dev but differ from Wayfatours Production: save control and error states, dynamic original JetEngine fields, optional JS dependencies and CSS repeater fixes. Never deploy these older production files over staged current files; compare and verify before production rollout.

## Full closure criterion
Commercial Rate generation requires per-component signed cost receipts, deterministic calculation order, no duplicated markups, currency and passenger correctness, provider readiness, audited approvals, UI acceptance and release CI. Record each blocked prerequisite explicitly and do not present a checkpoint as passed without testing it.

## CP2 UX continuation — product dimensions and accordion harmony (20 Nov 2026)

- Existing unified native JetEngine shell from commit 3c92bb9a86 and Guide Costs extension 87aceeef81 are retained. This checkpoint improves that same surface without adding another Workspace or option store.
- Products & supplier services shows a single read-only progressive configuration line in Accommodation, Transportation and Guide Costs: Step 2 — Choose active pricing dimensions. It identifies the original source editor and that audience/schedule/destination dimensions are company-wide.
- Configure dimensions clicks the existing Company-wide settings / General & availability section and opens the original dimension chooser. No duplicate dimension preference, taxonomy field, supplier cost, or POST field is created. Keyboard focus moves to the actual dimension control.
- Top-level source disclosures (Stay, Transfer, Provider, Supplier Inspector) share a header, border, chevron and expanded state. Secondary native policy/filter disclosures remain subordinate. CSS specificity matches the original composer. Open panels allow dropdown overflow instead of clipping select popovers.
- Residual grey JetEngine section chrome is removed only in Grouped JetEngine mode. The original form, repeaters, field widths and Save stay canonical. Native mode is untouched.
- Source and Wayfatours have matching on-disk shell JS/CSS hashes and rollback copies in /opt/mad4b-company-provisioning/backups/jetengine-shell-unified-v2-20261120/. Native form readback and shell enqueue tests passed on both sites. DOM tests verify three service modes, navigation, focus handoff and no duplicate fields.
- Authenticated browser visual checks at 320/390/768/1024/1440, native Save and repeater expansion remain OPEN. Commercial pricing checkout remains blocked. CP8 remains OPEN.


## CP2 final UI composition — true single Workspace (20 Nov 2026)

Supersedes the former "two separate DOM elements with matched borders" styling-only compromise, which visibly appeared as a second JetEngine editor below the Grouped Workspace. The original JetEngine vendor section (the exact same form, nonce, fields, native Save and handlers) is now physically placed in the central Workspace content in Grouped mode; it returns to its original root position in Native mode, without cloning or recomputing input values.

- One actual shared main layout contains the company/product navigation and central editor as sibling elements. No separate "page within page" border, negative margin workaround or duplicated JetEngine H1 in the visual mode.
- Header metrics now live within the original Workspace header as a compact strip, not three detached cards.
- The existing company/product separation and inherited Step 2 dimension controller remain unchanged.
- Original JetEngine save stays in regular document flow after the real fields. Mobile section navigation collapses to one accessible "Sections" toggle, revealing the existing navigation buttons only when requested.
- The runtime only moves a verified, unique native JetEngine form with settings_top and original submit; unrelated product-specific forms remain in place and keep their own Save owners.
- DOM acceptance: tests/verify-jetengine-native-editor-composition-dom.js proves exact input/nonce/submit object identity across repeated visual/native toggles, including coexistence with independent supplier forms.
- Chromium visual fixture acceptance: tests/verify-composed-workspace-playwright.py and fixtures/composed-workspace.html passed at 1440, 1024, 768, 390, 320. Assertions cover one native form in main content, no duplicate title, no page/content horizontal overflow, desktop common row, compact mobile menu open/close, in-flow Save, visual/native restoration. Fixture-based screenshots are **not** live logged-in WordPress screenshots.
- WordPress source verification: tests/verify-jetengine-composed-workspace.php and original native form/UI tests passed on both Tours.dev and Wayfatours with 54 native fields; source revisions unchanged.
- Live CSS/JS files were updated on staging and production, with per-site rollback backups under /opt/mad4b-company-provisioning/backups/jetengine-single-surface-final-20261120/.
- Authentication-based browser Save, real plugin interoperability, EN/ES responsive and commercial pricing E2E are still OPEN. CP8 remains OPEN.


## CP2.4 — Modern Flat Settings Canvas (20 Nov 2026)

After visual review of the real Wayfatours admin screenshot, the structurally merged Workspace still looked like separate pages because the shared JetEngine Field Recipe styled every regular control as a white card, while the preview, sidebar, and Save bar each had independent bordered shells.

Visual design contract: one neutral company pricing canvas; a single brand-consistent header; left navigation and right tour preview separated by hairline rules; central native settings expressed as label-and-control rows, not stacks of framed cards; original complex repeaters retain internal functional boundaries; the only primary save remains JetEngine's real original Save action; responsive controls stack below 1200px, and nav collapses on mobile.

Implementation: mad4b-jetengine-workspace-shell-v1.js toggles mad4b-ws-flat-canvas only when Pricing Controls is in Grouped Visual mode with the original native editor composed. Scoped rules in its CSS remove inner shell borders/shadows, flatten standard native controls, preserve native repeaters, integrate Save and Tour Preview, and remove redundant service shells. Original field names, input nodes and source settings remain unchanged.

Acceptance:
- verify-jetengine-native-editor-composition-dom.js, verify-jetengine-shell-product-context-dom.js, verify-jetengine-shell-nav-dom.js: original source field identities, view switch and Save accepted.
- verify-composed-workspace-playwright.py and fixtures/composed-workspace.html with actual Field Recipe CSS: 1440/1024/768/390/320, zero horizontal overflow, flat computed field border, original repeater, Save in normal flow, no nested workspace border, product context and mobile navigation accepted.
- Real WordPress server: verify-jetengine-composed-workspace.php, verify-jetengine-shell-ui.php, verify-native-jetengine-form-markup.php PASS on Tours.dev and Wayfatours. Original option source revision unchanged.
- Rollback backups: /opt/mad4b-company-provisioning/backups/jetengine-flat-canvas-20261120/
- OPEN: live logged-in browser real-data responsive and repeater evaluation, cross-browser and full business E2E. Not a certified commercial save or rate generation.

## CP2.5 — Shared product/interface language (20 Nov 2026)

Owner extended the reference design to all comparable settings/policy/price-source areas, not only the selected screenshot.

Problem: Accommodation, Transport and Guide were rendered by separate component owners with different presentation layers. Stay had separate group/quote boxes; Transport had independent source metrics, cards and steps; Guide had a WordPress postbox form and wide taxonomy table. Flattening native JetEngine fields left these product views looking like nested applications.

Introduced presentation-only asset mad4b-pricing-interface-v2.css, enqueued after the existing grouped shell by mad4b-jetengine-workspace-shell-v1.php, scoped to authorized Pricing Controls and the active mad4b-ws-flat-canvas view. One token set and common component rules cover:
- Accommodation original Property/Unit selection, step levels, filters and supplier preview.
- Transportation original transfer/route controls, source summary, steps and reference calculator.
- Guide Taxonomy original fallback form and native language/approval table, preserving independent horizontal scroll.
- Reusable dimension/policy/provider accordions and read-only supplier-cost inspection.
- Keyboard focus, adaptive fields, responsive source-preview placement and quieter selection/status components.

No service vendor file, supplier amount, approval contract, Rate, Checkout, source field, original Save or independent product Save was changed.

Checks:
- Design CSS static syntax/structure checks passed.
- verify-pricing-components-v2-playwright.py rendered original Stay and Transport vendor CSS followed by central style at 1440/1024/768/390/320, with no page overflow, consistent primary disclosure heights, Guide table internal scroll, and independent supplier form retained. This is fixture QA, not a live logged-in screenshot.
- verify-pricing-interface-v2.php passed on staging and Wayfatours. Original native shell and markup verification passed with unchanged source revision.
- VPS rollback copies at /opt/mad4b-company-provisioning/backups/pricing-interface-v2-20261120/{staging,wayfatours}/.
- OPEN: authenticated browser QA for all product modes, original JetEngine Save/Repeater, independent service Save/Approve, and commercial Rate/Checkout E2E.

## CP2.7 — Align company/product panes, reclaim repeater space, deduplicate registry (20 Nov 2026)

Owner screenshots highlighted inconsistent horizontal alignment between company settings and accommodation/transport, repeated policy information across tabs, and descriptions monopolizing space before the original JetEngine repeaters.

Root cause:
1. Legacy source composer allocated two root columns to product panes, while company settings used three with a Tour Inspector. At 1280 and 1024px, sidebar widths diverged due to unrelated breakpoints.
2. JetEngine's original CX inline repeater kept a split label/content width even after ordinary fields were flattened.
3. Read-only Unified Policy Registry was built in BOTH Overview and Pricing Policies, with its entire Policies table preceding editable native repeaters.
4. Dimension chooser showed WPML/Rate authority and four long action descriptions ahead of the dimension groups; product pages repeated the global Step 2 heading.
5. Company-only search was above the shared navigation, shifting the left sidebar on product pages.

Resolution:
- One composed Canvas layout contract aligns content x and sidebar widths at 1440/1280/1024/768/390/320; supplier quote rails remain native and adapt at container width.
- The SAME company search input moves next to the company section title, preserving original handler and input value. Product-specific searches remain untouched.
- Original CX native repeater content and heading fill 100% of available main editor width; no clone or field rename.
- Full read-only policy registry has exactly one owner: Pricing Policies. A compact count and closed details precede native editable repeaters; Overview provides one direct navigation action opening that details view.
- JetEngine dimension groups follow a compact four-action toolbar and readback status directly. Long WPML/Rate explanations and all four per-action captions remain available after groups through native disclosures with intact ARIA references.
- Product tabs have a compact shared-inheritance note instead of repeating the company's Step 2 configuration page.
- No original source controls, vendor templates, forms, nonces, Save/approval handlers, Tour Rates, checkout or prices were modified.

Evidence:
- verify-pricing-layout-regression-playwright.py uses the frozen real legacy composer CSS in fixtures/pricing-composer-vendor.css, the actual JetEngine styles and current UI shell. Chromium passed six viewports with exact company/product content-left alignment, zero overflow, full-width repeater content and verified descriptions order.
- verify-jetengine-shell-product-context-dom.js, verify-jetengine-native-editor-composition-dom.js and verify-jetengine-shell-nav-dom.js passed.
- verify-pricing-layout-ux-source.php, verify-jetengine-shell-ui.php, verify-pricing-interface-v2.php, verify-jetengine-composed-workspace.php and verify-native-jetengine-form-markup.php passed after deployment on Tours.dev and Wayfatours. Both retain 54 original fields and unmodified settings source revision.
- VPS backups: /opt/mad4b-company-provisioning/backups/pricing-cp27-align-and-deduplicate-20261120 and /opt/mad4b-company-provisioning/backups/pricing-cp27-registry-compact-20261120.
- OPEN: real authenticated browser visual, full native Repeater Save/Company setting persistence, independent product Save/Approval interactions, and commercial E2E. Fixture QA is not a live browser certificate.

## CP2.8 — One owner per field, no cross-tab duplicates, live source reconciliation

Confirmed root cause: real Wayfatours JetEngine fields had the correct
mad4b-ws-filtered flag, but higher-specificity display:flex/grid!important
selectors forced those fields visible on unrelated tabs. The error affected
normal fields and nested repeaters, not merely a duplicated label.

Corrected the native Field Modes CSS and Pricing Canvas CSS filtered state.
Replaced heuristic pricing-field grouping with a unique explicit 45-field
owner table and Advanced fallback for future fields. All native inputs remain
under the same original Save form. Legacy empty JSON pricing controls, old
booking-hours control and optional currency override are assigned only to
Advanced; current booking lead days live in Dates & Booking, while the
canonical WooCommerce/WCML catalog stays at Currencies & Markets.

Original native labels are associated with the existing inputs by ARIA.
Repeater switcher labels are generated from the native row/title and updated
on newly inserted or reordered rows; no input is cloned or disabled.
Product scope panels warn about unsaved JetEngine company edits and route
users back to the original native form instead of adding another Save.
Engine ON is labeled configuration-only, not a commercial Rate approval.

Read-only tests: six sections across widths 1440/1280/1024/768/390/320
show exactly their own fields, zero filtered visible elsewhere, 45 unique
control ownerships, nine native Repeaters, one form and unchanged test
values after navigation. Original Wayfatours HTML changed from 116/177
directly named inputs to 177/177 in isolated Chromium replay. This is
not a live accessibility or authenticated Save certificate.

Phase-one fixes were found on both live sites with identical source SHA
and passed native WP tests. Follow-up changes need separate source/live
verification and post-release browser QA. Additional missing pricing
plugin source files were copied into the Git working tree, not deployed.
Commercial supplier inputs and the original checkout remain unchanged.

## CP3.0 — Native writer cross-connection lock and keyboard/repeater regression

Date: 21 November 2026.

The original JetEngine save admission was verified on Tours.dev and Wayfatours,
using real WP-CLI WordPress bootstrap, real capability/nonce/revision checks
and the real MariaDB connection. Four guarded request modes passed on both:
GET denied, unsigned POST denied, stale signed revision denied, current signed
revision admitted without calling vendor save.

A new test, verify-native-pricing-cas-two-connection-lock.php, uses two distinct
live MySQL connections. While the legitimate pricing CAS lock is held by the
first, GET_LOCK(...,0) from the second returns 0. Immediately after release
the second connection acquires and releases the same key successfully.
The original pricing option HMAC before/after stays unchanged, and no native
Save is executed or pricing option is written. This demonstrates real database
serialization but is NOT an authenticated form submission / full save-write E2E.

Playwright cross-tab UI test extended: 45 unique fields across six navigation
tabs and six responsive widths; no hidden field becomes tabbable while another
section is active. Keyboard Enter activates original section navigation on
desktop widths. Newly inserted repeater switcher inputs receive accessible
On/Off names; reordering a row updates names and row ordinals. A pending
tax_percent input and original repeater switcher edit both survive switching
away and back within the same native form. Neither is submitted to a live site.
This is an isolated rendered-form interaction test, not an axe certification.

Real JetEngine Option Page registry inventory across both companies found
three registered pricing-related pages: Pricing Controls (45 editable fields),
calculate-booking-price (2), and Company Policy Library (89). They have zero
identical native option field keys across page pairs. This rules out exact-key
duplicates among these page factories, NOT semantic duplicates between
separately owned Pricing Lab / Product CPT / Transport controls.

## CP3.1 — Repeater duplicate overview and production-source parity

Date: 21 November 2026.

The large second read-only Live overview rendered above nine original
JetEngine Repeater editors caused stacked nested cards and redundant
descriptions. Pricing Controls now mounts its former overview in a
keyboard-operable, closed-by-default native details disclosure inside
the original field information block. Original row controls, contextual
row summaries and JetEngine Save remain unchanged. No source or price
policy is copied to a new editor. Other non-pricing JetEngine pages
preserve their historical overview presentation.

The preview AJAX call is deferred until the operator opens a disclosure.
No preview call is sent during a normal Pricing Controls page load with
all disclosures closed. Opening by keyboard Enter fetches exactly one
read-only preview; closing hides it without affecting the saved fields.
New Playwright test verify-repeater-optional-overview-playwright.py covers
both pricing and nonpricing behavior.

The Wayfatours live repeater-overview.js was older than the Git/source
file: it inserted a duplicate sibling overview for Price Source Modes,
whereas the source already had a compact per-rule summary and no large
source mode overview. After source diff review and exact predeploy SHA
verification, both sites now run the latest source file plus CP3.1
changes. Each has a separate on-host backup of both modified UI assets.
WordPress native UI and CAS markup tests passed after the deployment
on each site, with both sites returning HTTP 200. No pricing values,
payment status, booking orders or live Tour Rates were changed.

Open after CP3.1: full authenticated browser vendor Save and dual-session
real form E2E, completion of CSS design-system consolidation, and the
separate commercial supplier cost/FX/children readiness work.

## CP3.2 — Real native HTML layout and read-only Repeater field contract

Date: 21 November 2026.

The original registered JetEngine Pricing Controls factory was inspected on
both Tours.dev and Wayfatours (no database mutation). Four original Repeater
schemas were verified with native child field names:
shared_mode_policy_rows (21), shared_package_pricing_rows (6),
shared_gateway_availability_rows (6), mad4b_provider_source_policy_rows (11).

New read-only test verify-native-repeater-readonly-roundtrip.php invokes
the ORIGINAL Jet_Engine_Options_Page_Factory native sanitizer for
multi-row field samples, then WordPress serialization/unserialization,
and verifies every row and child value. The authoritative CAS source
revision is identical before and after on both live sites. No option,
Rate, commercial policy, order or checkout state was modified.

New Playwright test verify-rendered-jetengine-native-form-playwright.py
consumes original vendor native HTML exported by read-only WP-CLI earlier
(private fixture kept under /tmp, never checked into Git because it
can contain option values). It applies actual source CSS/JS without
requesting any remote endpoint. Tested 1440/1024/768/390/320 and all
six tabs: 45 native owned fields, exactly one original form and Save
button, no filtered controls resurrected, no significant horizontal
overflow, desktop keyboard Enter navigation and mobile section menu.
All five-by-six real-DOM tests passed; authenticated Save is NOT proven.

An ephemeral privileged WordPress authentication session attempt for
browser testing was blocked by platform access safety checks, and was
not retried or circumvented. A synthetic transactional writer test
attempt was also blocked before execution. Original vendor writer
round-trip, logged-in Save confirmation and two-browser stale response
remain OPEN. No pricing settings changed.
