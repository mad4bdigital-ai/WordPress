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
