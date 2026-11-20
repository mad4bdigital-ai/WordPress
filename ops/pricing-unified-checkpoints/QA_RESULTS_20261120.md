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
