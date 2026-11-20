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
| CP3 | Single supplier-cost evidence schema and composer: no retail substitution, duplicate lines, missing or expired FX evidence | CONTRACT PASS, DATA BLOCKED: 10 denial cases, quantity and missing-component tests passed on both sites; real supplier receipts incomplete |
| CP4 | Deterministic integer-minor-unit shadow evaluator with markup vs margin separation, fixed rule ordering, duplicate protection; full FX/passenger/provider integration separately gated | SHADOW PASS, COMMERCIAL OPEN: 7 unsafe cases rejected on both sites; actual cost, FX, occupancy/children receipts still missing |
| CP5 | AJAX native entity selector and progressive disclosure of 16 financial fields in 21-field policy repeater; no extra Save or hidden disabled native values | SERVER+DOM PASS, BROWSER OPEN: original input and change events tested, logged-in UI Save/mobile/real repeater markup not yet certified |
| CP6 | Unified native policy write/approval gateway with RBAC, revision, validation and original writer ownership | NOT STARTED |
| CP7 | E2E matrix: Wayfa 84 Tours, 8 package templates, EN/ES, rate/occupancy/children/FX, mobile 320/390/768/1024/1440 and checkout gate | NOT STARTED |
| CP8 | Local Git/GitHub/live fingerprint parity, all CI green, approval and merge/release evidence | NOT STARTED |

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

## CP2 runtime findings
- Original Wayfatours module.php does not include the optional Recipe Registry and its styling assets, unlike Tours.dev. The first attempted three-file upgrade **failed** during a WP-CLI asset test and was rolled back to exact original hashes, verified with HTTP 200.
- A source-neutral guard now calls the Recipe Registry only when the class exists, and enqueues optional Recipe field CSS only when available.
- Deployed the source core class, Functional Modes JS/CSS, and optional CSS only after staging acceptance. Production test passed the native field schema, 9 compatible UI Modes and registered dependencies, HTTP 200. Source CSS/JS hashes match both sites.
- Did not replace module.php or install the entire Recipe Registry on production. The browser-authenticated Save and mobile repeater acceptance remains open.

## CP2 source reconciling notes
Functional Modes source PHP and JS in Git match Tours.dev but differ from Wayfatours Production: save control and error states, dynamic original JetEngine fields, optional JS dependencies and CSS repeater fixes. Never deploy these older production files over staged current files; compare and verify before production rollout.

## Full closure criterion
Commercial Rate generation requires per-component signed cost receipts, deterministic calculation order, no duplicated markups, currency and passenger correctness, provider readiness, audited approvals, UI acceptance and release CI. Record each blocked prerequisite explicitly and do not present a checkpoint as passed without testing it.
