# Wayfatours: Package 8 Booking v2 acceptance checkpoint — 20 November 2026

**Status: native input/preview contract tested on live site, commercial E2E and payments remain blocked.**

## Bounded real-runtime readback

Site: `https://wayfatours.com`. Script `scripts/mad4b-package8-booking-v2-failclosed-cli.php` calls native `shared_product_booking_v2_validate()` and `shared_product_booking_v2_preview()`, only, for 8 existing English Package Tour posts [57562,60437,60439,60441,60443,60623,60627,60629]. Matrix = **8 Tours × 3 synthetic passenger examples (2 adults / adult+child aged 8 / adult+infant aged 1) × 3 booking intents (quote, request, instant) = 72**. Sample date **2027-01-15** is a test parameter, not a supplier-verified travel date.

All calls are non-paying, no real guest identity, no Woo cart write, no order, no payment, no Fluent submission or email send. **No final customer price has been authorized.**

| Outcome | Number |
| --- | ---: |
| Total tested | 72 |
| Native structural input validation accepted | 40 |
| Input rejected by built-in validator | 32 |
| Indicative quote preview accepted with `checkout_ready=false` | 18 |
| Preview rejected by provider | 22 |
| Instant requests authorized | 0 |
| Child or infant price certified | 0 |

Per intent:
- **Quote**: 18 non-bookable indicative-only previews succeeded. Six quotes rejected `booking_v2_program_ineligible`, all from English Tour IDs 57562 and 60437 (three passenger cases per Tour); do not modify the schedule to make a synthetic test pass.
- **Request**: 8 adult cases rejected `fulfillment_intent_not_permitted`; 16 child/infant cases rejected `booking_v2_age_pricing_not_certified`.
- **Instant**: identical 8 fulfillment and 16 age-pricing blocks. None issued payment or booking authorization.

### Independent side-effect and storage verification

The test captures the live transaction baseline immediately before the 72 calls and again afterward, and **fails if anything differs**:
- WooCommerce HPOS feature detection and **all** WooCommerce order statuses/modified data (not only `publish`); HPOS currently disabled on this site, therefore native `wp_posts` holds **7 existing Woo order records**, with signatures unchanged.
- Fluent Forms submissions (currently **15 existing submissions**), signature unchanged.
- All native `tour-rates`, `property-rates` and `transport-rates` post status/modified-time records, signature unchanged.
- No activation of Company Policy, cost receipts, supplier inventory, child pricing, multi-currency settlement or Woo payments.

These tests are **fail-closed validation**, *not* a passing payment/commerce E2E. Cross-date availability, nationality, room occupancy, supplier cancellation, FX, 14% Woo tax, 50% deposit/balance schedule, Stripe order callbacks and actual notification delivery still require separate **authorized, non-financial sandbox E2E** with certified actual supplier-cost receipts and active company policy. Never call a successful indicative preview an approved client quote.

### VPS evidence

`/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/package8-booking-v2-failclosed-results.json` was generated in read-only WordPress execution. PHP error output contains nonfatal FluentCRM MCP ability registration warnings; the WP-CLI process and contract assertions succeeded.

**Gates to change commercial readiness:** restore independently enrolled site-bound CompanyCommercePolicy (see #992 and #993), approve guide day coverage/itinerary 66-day matrix, approve costs and availability for 56 Accommodation Stages, select and verify seven Cruise Programs and signed day sequences, certify child/infant pricing and FX/tax and transport route costs, then run sandbox request/payment/email acceptance before customer checkout. No fabricated rates.
