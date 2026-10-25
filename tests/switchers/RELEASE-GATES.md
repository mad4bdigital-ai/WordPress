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
