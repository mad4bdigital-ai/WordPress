# Country & Traveller Nationality switchers — QA release boundary
- This branch contains standalone switchers and the MAD4B shared source / market context required for Country.
- The QA plugin ZIPs are **not** a deployment-ready Control Plane upgrade. The Control Plane must use its governed packaging, compatibility and rollback process before Country is activated elsewhere.
- CI checks PHP syntax, source ownership, no price/currency interception, dependency presence and reproducible archives. These static checks do **not** replace WooCommerce checkout integration tests.
- Verified separately on ETG WooCommerce Local Lab 2026-10-25: Country/Nationality PHP 39/39; Blocks PHP 20/20; Store API simulated cart HTTP 200.
- Not yet certified: real booking-engine traveller count, real full checkout + sandbox payment, MaxMind MMDB, CDN cache separation, registered-customer session, Staging and Production.
- Do not transfer any `qa-*` market approval records or secrets to other environments. Do not enable promotional/seasonal offers.
- The existing `etg-` plugin folder, action names and metadata keys remain temporarily for backward compatibility. Reusable naming / migration is a separate guarded change.

- Traveller count now reads the existing server-verified Tour Rate v1 booking quote `shared_booking_configuration.quantities.persons`, even with Woo `quantity=1`. Booking v2 remains preview-only; unsupported or unverified lines refuse checkout (zero count), not inferred from Quantity.

## Booking count correction validated 2026-10-25
- Current WooCommerce-native Tour Rate cart source: `shared_booking_configuration` created and refreshed by the existing server quote adapter. Count is `quantities.persons`, not `WC Cart quantity` (one booking).
- The separate Booking Configuration v2 `quantities.persons` and Fluent Forms `total_travelers` contracts were discovered, but their present statuses are `structure_only_not_ready_for_cart_write` / `normalized_only_not_checkout_authority`. They must not be promoted into paid Checkout data without their source's own financial booking authorization.
- Both Checkout Blocks and Classic Checkout refuse unverified/missing or mismatched traveller counts. No market, currency, pricing or billing fields are edited by the switcher.
- Local Lab real Tour Rates 66139 (3 people) and 66138 (2 people) with Woo Quantity 1: 20 assertions passed including Store API cart schema, nonpersisted in-memory Woo Order line-item meta, Classic validation, tampered server quote rejection, duplicate retry rejection and unchanged currency. Test is `tests/switchers/test-local-real-booking-count.php` and is intentionally not run inside GitHub Actions without the lab fixtures.
- GeoLite2: Woo integration is installed but license key and MMDB are absent. No key was created or copied, and Woo default customer location remains `base`.
- Guest AJAX response has `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` and `X-LiteSpeed-Cache-Control: no-cache` in Local Lab. Real CDN edge isolation still needs Staging acceptance.
- Existing MAD4B release governance checks, including exact reviewed-source provenance, are not waived by this QA change.

- Real plugin React UI loaded in an isolated Chromium harness (without making the Draft QA page public): five verified nationality selectors plus one unverified booking alert at 1440px/390px, with no JavaScript errors or horizontal overflow. Run manually with `python3 tests/switchers/test-blocks-ui-browser.py`; requires Playwright and mounted Local Lab assets. This does not prove a full checkout payment.

## Traveller Management 0.4.0-local — optional per-product configuration
- Five built-in presets (automatic/tour/accommodation/transport/extra), versioned overrides, editable copies, global default selection, per-product/CPT preset dropdown, clone, reset, and optimistic revision checks. WooCommerce admins use WooCommerce → Traveller Management, linked from Booking Experience settings.
- Strict 20-person Checkout cap remains in force even if a preset requests 50 or 100 for future QA.
- Tour Rates use the server-requoted quantities.persons; do not equate Woo Quantity, group pricing tiers or currency with people. Uncertified Booking v2 preview, accommodation shadow quotes and transport reservations are explicitly not authorized.
- A future verified adapter can register a count for its matching product type only when checkout_ready=true, with the exact product ID and Woo quantity. This interface alone is not an installed payment integration.
- The in-memory per-booking registry holds opaque IDs, count, and optional verified service links. Linked services can inherit selected existing traveller indexes without duplicate nationality fields. Classic and Blocks order items record opaque registry IDs and participation indexes, while root order items retain the passport-nationality metadata.
- New CI tests: test-management-unit.php, updated hermetic booking-count test, and static validation. Local Lab test scripts: test-unified-traveller-lab.php, test-linked-traveller-services-lab.php, test-local-real-booking-count.php and existing Playwright browser harness. Local test options are restored after each test and no paid order is created.
- Outstanding: financial Booking v2 approval, Accommodation and Transport certified Checkout adapters, actual frontend participant picker tied to server booking identity, HPOS GDPR retention/export acceptance, enlarged group capacities, MaxMind and real CDN/staging isolation.
- Security regression: legacy required_count filter cannot turn an unverified booking into a verified person count or alter the server-certified count (2 new fail-closed tests).
- The older `test-checkout-blocks-nationality-20261025.php` lab fixture expected `Quantity=travellers` and is deliberately superseded; run `test-local-real-booking-count.php`, `test-linked-traveller-services-lab.php`, and `test-management-unit.php` instead. Restoring the old semantics would reintroduce the data-authority defect.
