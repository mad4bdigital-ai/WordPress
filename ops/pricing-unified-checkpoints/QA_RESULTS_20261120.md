# Pricing & JetEngine UX: integrated checkpoint evidence (20 November 2026)

Source: tours.dev.mad4b.com staging. Target: wayfatours.com production. Branch feature/pricing-unified-checkpoints-20261120 stacked on Draft PR #965.

**Commercial pricing release NOT approved.** New calculators, cost validators and preflight endpoints are internal/read-only. Existing Tour Rates, public prices and Checkout stay under their original source authority.

## Checkpoints

| Checkpoint | Tours.dev | Wayfatours | Outstanding gate |
| --- | --- | --- | --- |
| CP0 | Original preview source SHA verified; #965 CI 5/5 | Matching SHA | New branch CI and release |
| CP1 | 228 WPML relation checks passed | 477 checks, 87 translated, 193 unassigned rejected, 0 failures | Authenticated browser cross-language selection |
| CP2 | Nine JetEngine Modes, 53 Pricing and 31 Service Activation fields pass | Same server contract after missing optional Recipe guard, original files restored from first failed attempt | Browser native Save/repeater/mobile |
| CP3 | Cost-only contract, 10 risk denials, quantity and missing cases pass | Same and matching source SHA | Real certified component receipts |
| CP4 | Shadow integer minor-unit evaluator; 7 unsafe cases rejected | Same and matching SHA | Real FX, passengers, taxes, native Rate integration |
| CP5 | AJAX lookup, DOM input/change, advanced-field disclosure pass | Server lookup and asset hash parity pass | Actual browser Save, row handling, responsive |
| CP6 | RBAC, Draft and Binding revision, malformed IDs blocked, zero writes | Same, native JetEngine Options writer blocked | Source-owned optimistic CAS, approvals |
| CP7 | 221 published Tours and 116 assigned package terms, all preview blocked by unconfigured source preview policy | 84 Tours, 197 selections, 192 previews good, 1 disabled Platinum, 4 base price missing | Eight-package full E2E, actual supplier data, browser |
| CP8 | Not approved | Not approved | CI, signed release, merge, rollback receipt |

## Live data and financial release boundaries

Wayfatours Tour Rates: 0 publish, 106 Draft, 2 auto-draft. Tours.dev: 196 publish, 29 Draft. Source and target pricing policies differ. No read-only test changed pricing options or Rate status.

A complete synthetic component-cost fixture is not proof of a real supplier invoice. The public package preview still applies Tour Type and Package Level markup to old retail start_price, not a verified cost. Checkout and commercial Rate generation must remain gated.

## CP5 / CP6 restrictions

- Native entity picker writes only the chosen ID to JetEngine original input and emits input/change; its internal search is not a saved field.
- Conditional controls never remove/disable native input values or add a second Save.
- The authenticated Edge browser tab cannot be read through the connected browser in this session, so browser UI Save/resize remains unverified.
- CP6 is preflight only; it does not call original native writer. Options Page save increments source revision without enforcing expected revision, so true transactional writer unification is not yet possible.
- A broad suite rerun was blocked by the STAGING_WPCLI_BUSY resource guard because other WP CLI work was running. Do not record this attempted rerun as completed; individual checkpoint tests and CP7 source matrix were already verified.

## Release blockers and owners

1. Data/suppliers: certify hotels/units, meal plans, occupancy, supplier rate, transport distance-time & BASE, guided days and languages, extras inclusion.
2. Pricing policy owner: approve margin vs markup, FX receipt, applicable taxes and child/infant rates, policy precedence and cancellation treatment.
3. JetEngine developer: add source-native expected-version check and signed approval without duplicating options.
4. UX QA: logged-in real-browser test of Save, repeater add/edit/remove, AJAX picker, validation messages, EN/ES, and 320/390/768/1024/1440.
5. Release owner/CI: new branch workflows green, Git/live SHA audit, rollback evidence, explicit approval.

CP8 must remain OPEN until those conditions are fulfilled.


## 20 November addendum: Source CAS and Package supplier readiness

- Native JetEngine Options Page etg-dynamic-pricing-controls is protected with an HMAC fingerprint over 54 original SEPARATE option values read directly from DB. Original form receives signed hidden revision and nonce; admin_init priority 39 guard gets a MySQL advisory lock before JetEngine save priority 40. After-save or shutdown releases lock; no duplicate write store.
- Both source and Wayfatours passed tests/verify-native-pricing-cas.php and tests/verify-native-pricing-cas-dom.js. An unsigned vendor POST was rejected in an isolated staging simulation without altering source fingerprint. Authenticated parallel browser save remains unverified.
- Policy Write Preflight was updated to read source CAS revision, return native_validation_required and still perform no write.
- New MAD4B_Package_Supplier_Admission_V1 inspects original Stay Package Program bindings, Transport Self source, Guide composition and Extras quote runtime without relying on historic selling prices. All cost eligibility decisions fail closed until real supplier evidence is signed.
- Eight canonical Wayfatours Tours 60629,60627,60623,60443,60441,60439,60437,57562 were checked in EN/ES, EGP/USD/EUR/GBP, adult-only and adult+children+infants: 128 checks; 0 commercial approvals. Unselected room and missing stay cost (128), unapproved route cost (128), guide coverage not reviewed (128), extras supplier rate not certified (128), company margin and tax pending (128), FX needed (96), child/infant rates needed (64). No Rates or pricing options mutated.
- Eight Tour Property Stage x Package Level backlog exported to reports/wayfatours-eight-package-supplier-gaps.csv: 60 real source rows, zero commercially approved room units. Selected cruise vessels/programs and supplier prices remain missing on relevant stages.
- Actual room source receipts are editorial, rates intentionally unset; transport source records use vehicle_rates_unverified; Extras source runtime explicitly marks rate_eligibility_verified false.
- Source Tour Rates 196 Published and 29 Draft; Wayfatours 0 Published, 106 Draft and 2 Auto Draft. No commerce activation.

Remaining gates: real approved dated hotel/boat unit costs, verified transport vehicle-route costs, Guide day coverage, extras cost, FX, company margin/tax, child pricing, real browser dual-tab Save acceptance, updated CI and release owner approval. CP8 remains OPEN.


### Additional rendered JetEngine admin markup acceptance

Both sites passed tests/verify-native-jetengine-form-markup.php using the real native JetEngine page factory. One original form and Save button exist, and the actual Mode/Package Repeater input-name patterns match. Staging has no stored Package rows and Production has six; both are supported. Option source revision is identical before and after HTML rendering. The previous busy staging rerun later succeeded. Browser-authenticated click/Save and responsive testing remain separately open.


### 20 November addendum: UI review source visibility and HTTP method guard

- Reproduced a native JetEngine Save bypass: the original vendor save action accepts a GET REQUEST action, while the first custom CAS guard only inspected POST. The guard now intercepts all requests that name the vendor save action, rejects any non-POST request before the vendor action, and enforces signed source revision for POST.
- Tests/run-native-cas-request-regression.sh and tests/verify-native-pricing-cas-request.php passed on Tours.dev and Wayfatours for GET, unsigned POST, stale POST and current signed POST. All tests call only the pre-save guard, not the vendor write operation. No original pricing option changed. Live code and local Git SHA matched after the deployment.
- A new read-only admin panel displays native supplier eligibility from original Stay, Transport Self, Guide and Extras sources. Available in Pricing Controls for authorized managers. It requires an admin nonce and never writes a Rate or source field. User can select Tour/date/currency/occupancy; blocker details and source Tour edit link are displayed on demand.
- Files deployed with backups on both sites and accepted by tests/verify-package-supplier-admin-ui.php plus tests/verify-package-supplier-admin-ui-dom.js. This is not a completed authenticated-browser UX inspection.


### Supplier Readiness UX placement

Moved full read-only Inspector into Price Sources and reduced Overview to a compact summary. The published Tour Preview is the canonical Tour selection; its CTA opens the inspector and switching tours clears old diagnostic receipts. Original JetEngine inputs and Save remain untouched. Both sites passed backend dependency and actual form markup checks, plus standalone DOM tests. Two staging attempts were rolled back automatically: first STAGING_WPCLI_BUSY, then a WP-CLI test that needed to reproduce the real priority-55 native script enqueue. Corrected staging and Wayfatours deployments passed with matching four source hashes and HTTP 200. Authenticated graphical browser/mobile checks are pending.


### 20 November addendum: JetEngine single workspace surface

Source inspection found a duplicate native JetEngine cx-section__info title and visual gap between the original form and MAD4B workspace. Accommodation and Transportation each wrapped their source tools in additional differently styled details cards.

A separately reversible MU presentation layer now joins the native form and workspace into one visual paper in Grouped View, hides only the duplicated page title, flattens the outer Product accordion shell, standardizes native disclosure styling, and adds Company-wide versus Product / Supplier navigation headings. No original control is moved, named differently or saved through an alternative writer. Native JetEngine View stays unchanged.

Accepted on Tours.dev and Wayfatours: PHP syntax, CSS brace check, standalone JS navigation/late-service DOM tests, pricing admin enqueue/authorization, Site Service Activation Options Page enqueue, unchanged native source revision, 3/3 live/source file SHA, public HTTP 200. Authenticated graphical browser comparison at mobile/tablet/desktop is still pending.

Deployment caveat: original pricing composition JS and CSS already exist on both live sites but their script sources are not tracked on the current #987 branch; this new layer does not overwrite them. Reconcile their baseline source separately before any broader plugin re-release.


### Guide Costs shell parity follow-up

Guide Costs is a separate Global + Taxonomy Term approval panel rather than an external pane owned by the Accommodation/Transport composer. The shared shell now recognizes the guide-cost-tab-active state as a Product section, avoids company form joined-bottom-border styling while native controls are hidden, flattens the Guide panel legacy postbox frame, and groups its navigation under Products & Supplier Services even if it mounts first. Staging/Production parity and tests passed. No Guide policy was saved.

### 20 Nov follow-up: original JetEngine shell consolidation v2

The existing shell was extended rather than replaced. Company-wide settings and product source panels remain separated in one navigation. Each Accommodation, Transportation and Guide Costs pane displays an inherited-dimensions Step 2 summary with a button to open the single existing company dimension controller. Keyboard focus follows the actual control. No product override store or new rate/source writer exists.

CSS removes residual outer JetEngine grey chrome, normalizes primary/secondary accordions with consistent border, background, open divider and chevron, and ensures expanded service panels allow custom dropdown overflow. The original native form and fields are unchanged. Visual mode only.

Read-only verified tests:
- verify-jetengine-shell-nav-dom.js PASS.
- verify-jetengine-shell-product-context-dom.js PASS (new).
- verify-package-supplier-admin-ui-dom.js PASS.
- CSS static scope/brace integrity PASS.
- verify-jetengine-shell-ui.php PASS on both sites; original source revision unchanged.
- verify-native-jetengine-form-markup.php PASS on both sites; real native form, Save and repeaters intact.

The two shell JS/CSS files were deployed to staging and production with source SHA parity and rollback backups. No authenticated graphical browser/mobile/real Save certificate has been issued. Commercial pricing remains blocked by supplier evidence.


### 20 Nov final UI correction: structural integration, not cosmetic camouflage

Replaced the prior CSS-only seam joining with one actual shared visual editor. The existing native JetEngine form is moved intact into the central content when Grouped View is on and restored when Native View is selected. Its field object identities, input names, nonce and submit remain unchanged. New guard allows separate supplier-specific forms without confusing them with the unique JetEngine original. The header statistics are nested in the existing header, and mobile navigation collapses rather than consuming the whole screen. Native Save remains in normal flow after the inputs.

Testing: original WordPress factory form readback (54 fields), component script/style verification on staging and Wayfatours, DOM identity tests, Chromium at 1440/1024/768/390/320 with zero horizontal overflow and mobile navigation click/close, intact source revision. No live authenticated browser certificate issued. All changes are scoped to the presentation shell; commerce and supplier cost approvals were not changed.
