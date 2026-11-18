# Form #6 — native Rate, single-stay and pricing-tier parity

This is the exact on-server UI source read from Tours.dev and Wayfatours Production on 18 November 2026. Both live site copies were byte-identical for the three files recorded in manifest.json. The Git commit/PR does not itself redeploy or authorize a financial release.

## Reproduced behavior
- A Rate's one eligible linked Property is selected by the shared selection broker, with its native JetEngine card marked selected/aria-checked=true. Multiple-property stages continue to allow an explicit choice.
- Form #6 does not repeat a single predetermined hotel. Room Type, Meals and Number of rooms are hidden unless the source-linked Property exposes eligible stay units/boards. Do not create fictional options.
- Native Elementor Single, Double, Triple price cards become the explicit pricing-tier selector while visible; Form #6 pricing tier is shown as the fallback after collapsing the accordion. Choosing another Rate clears the prior pricing tier.
- The original server still validates booking eligibility, dates and prices. This code does not certify availability or payment.

## Acceptance and cache caution
Offline gate: python3 ops/site-runtime/form6-native-rate-stay-20261118/verify-source-contract.py plus node --check of both JS files. Optional read-only live browser acceptance (requires Python Playwright+Chromium) is in qa/. Previously observed against the reference Rate IDs 60820 / 60830 at widths 412px and 1365px; these QA IDs are fixtures, not hardcoded into frontend implementation.

Known issue outside this source change: LiteSpeed compiled JavaScript can remain cached independently of the origin files. A future deployment must generate content-addressed asset URLs and invalidate affected page/asset cache keys (including normal authenticated sessions and public variants) before claiming complete rollout. Do not assume a source SHA match means each browser has the new code.

## Source ownership
These three files are stored under their actual site MU-plugin paths and are not changes to the historically frozen packages/mad4b-shared-product-core Local Lab snapshot. The code is site-runtime presentation logic, not a replacement for the signed BookingExperience/Woo financial source. When converging the MAD4B platform's newer legacy-mu-tree and shared-core packages, reconcile these hashes against their latest upstream branch rather than blindly overwriting newer work.
