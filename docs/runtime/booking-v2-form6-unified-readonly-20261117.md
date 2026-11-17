# One native Booking V2 quote; Fluent Form #6 remains the intake UI

## Permanent original-source behavior
- Tours.dev (full Commerce): existing Booking V2 and native Form #6 adapter remain authoritative. Native WooCommerce checkout, order and cart hooks are unchanged.
- Wayfatours (Presentation): site-profile-bound `company-booking-v2-readonly-bootstrap-v1.php` explicitly activates **only** the original Booking V2 preview declarations and their original read-only quotation dependencies. It does not set `MAD4B_COMMERCE_RUNTIME_ENABLED`, create carts/orders, or permit checkout.
- The source native `shared_presentation_form6_price_quote()` delegates monetary calculation to `shared_product_booking_v2_preview()` in both modes. `MAD4B_Form6_Readonly_Price_Preview_V1` is now a compatibility facade around that same function; it never independently calculates money or overrides the original REST route.
- Original `shared_product_booking_configuration_quote()` is extracted unchanged into `pricing/booking-configuration-quote-readonly-v2.php`. Full Commerce's `woocommerce-booking-configuration.php` still owns its pre-existing cart/checkout hooks; tenant Preview loads **only** the pure function file.
- Seven additional side-effect-free original pricing/validation modules are permitted through a site-bound `MAD4B_BOOKING_V2_READONLY_ENABLED` admission flag; the original taxonomy editor registration is Commerce-only. No global permission is obtained from this flag.
- Form #6's original date/Rate/extra/age and language guards remain intact. Children/infants lacking certified policy still return a partial adult-only reference, never a zero-value child/infant charge. Tax is projected only through the original WooCommerce authority and is never inferred from the browser.
- The existing `inquiry_only`, `request_to_book`, or `instant_booking` option remains independently owned per company. A quote has `checkout_ready=false` and `order_created=false`, irrespective of runtime.

## Regression and live receipt
The PHP 8.3 and 8.5 workflow checks read-only declaration activation, rejected unadmitted modes and absence of new WooCommerce hooks in Presentation, plus the pre-existing source/concurrency checks. Before live deployment run a read-only WordPress simulation on a published Rate, validating normal adults, minor-age partial quote, outside-rate date and denied request/instant intent. Confirm the original Form #6 endpoint and template scripts on both sites, then compare amounts with preimage readback. Keep site-specific rollback backups and SHA256 manifest. Never create a real Fluent submission or Woo order during the read-only smoke test.

This is a selective manual correction, not an Original313 certified Production release. The PR requires its own CI and signed release path before being considered globally deployable.
