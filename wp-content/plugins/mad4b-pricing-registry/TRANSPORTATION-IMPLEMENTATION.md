# Transportation Pricing — incremental implementation (2026-11-19)

## Scope

This is an additive expansion of the existing MAD4B Pricing Registry, JetEngine
`transportations` CPT (definition 173), and native `transport_rates` CCT
(definition 304). It is **not** a parallel booking or pricing engine.

The staging-only first release adds:

1. A read-only classification/rate diagnostic in **Tools → Transport Pricing Readiness**
   and a link from **Dynamic Pricing Control**.
2. A read-only REST contract at
   `/wp-json/mad4b-pricing/v1/transport-readiness` and
   `/wp-json/mad4b-pricing/v1/transport-source-candidates`.
   Both require pricing/commerce permissions.
3. A 2026 transport source candidate matrix (51 operations, 210 price cells).
   The CSV fixture is outside the webroot; the PHP data file is guarded with
   `ABSPATH`. None of the candidate amounts are live or bookable.
4. Staging schema repair for `trans_pickup_type` and `trans_dropoff_type`
   to use the existing Pickup/Dropoff Glossary 340.
5. An expand-only native JetEngine CCT migration for 11 new pricing dimensions,
   retaining the existing 24 fields and both existing CCT rate records.

## Existing taxonomy contracts

- `transportation-types`: mode root IDs 568 (Road, legacy “Street”), 578
  (Rail), 581 (Water), 594 (Air), 595 (Adventure). Keep term IDs stable.
- `transport-service-type`: private, shared, chauffeur, rental, transfer;
  these are cross-cutting service descriptors, not transport modes.
- `service_mode`: seven legacy glossary choices decomposed into route,
  scheduling, inventory and composition dimensions. Existing values stay intact.
- `vehicle_classes`: economy, premium, luxury, a commercial class rather than
  a true vehicle-size/category matrix.

## Rates and data provenance

The attached source is **Transportation 2026 - Sheet1 (2).pdf**,
SHA-256 `c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd`.
It states an effective date of 2026-04-01. Aswan and Luxor prices are labeled
*before 5% discount*. It does **not** establish currency, whether each amount
is supplier cost or sale price, the final validity date, or availability.

- Cairo: 13 operations; Aswan: 15; Luxor: 23.
- Price values: 210 nonempty cells; no missing price is converted to zero.
- Review flags: Luxor row 7 has a nonmonotonic vehicle-price sequence,
  and Luxor row 23 has no prices.

**Import must remain gated** until source currency, cost/selling role, relevant
transport service/route mapping, the source anomalies, and end dates are resolved.
Never map this CSV directly to an on-sale rate based only on an inferred currency.

## Staging migration commands

WP-CLI (inside the Tours.dev PHP container, with its own documented backup):

```bash
wp eval-file tools/repair-transport-pickup-glossary.php --allow-root
wp eval 'define("MAD4B_TRANSPORT_APPLY",true);require "FULL_PATH/tools/repair-transport-pickup-glossary.php";' --allow-root

wp eval-file tools/expand-transport-rates-cct.php --allow-root
wp eval 'define("MAD4B_TRANSPORT_CCT_APPLY",true);require "FULL_PATH/tools/expand-transport-rates-cct.php";' --allow-root
```

CCT expansion delegates physical columns to JetEngine's native schema updater.
This updater checks `manage_options`, so the CLI migration validates and sets
the staging administrator explicitly before calling it. If the column update
fails, restore the original saved CCT definition via the rollback switch. Do
not use a partially updated definition.

Rollback switches, only if the schema has not changed since migration:

- `MAD4B_TRANSPORT_ROLLBACK=true` on pickup/dropoff migration.
- `MAD4B_TRANSPORT_CCT_ROLLBACK=true` on CCT migration.

Both scripts are explicitly scoped to staging or must be run only on a selected
site. No Production database migration is included.

## Tests and invariants

```bash
wp eval-file tests/transport-readiness-runtime.php --allow-root
```

The tests assert legacy normalization, mode/type mismatch detection, expired
rates, 51 source rows, 210 price cells, review flags, and read-only/no-booking
semantics. The provider's canonical published quoting and WooCommerce checkout
behavior remain unchanged.

## Deferred integrations

- Tour Rate Generator line-item composition, vehicle allocation and avoidance
  of double-counting; separate integration tests required.
- Booking inventory/reservation mutations and payment activation.
- Real-time Air/Rail fare availability and supplier contracts.
- Company-wide CCT migration until exact source parity and authorization.

## Canonical CPT promotion — completed on Tours.dev staging

The **existing** JetEngine `transport-rates` CPT (definition ID 358) is canonical.
It already existed before this work with 26 fields and 5 records; it was not
recreated. Two of those posts already traced back to the legacy CCT via
`source_cct_id` 1 and 2. The legacy `transport_rates` CCT remains archived
and readable for audit/rollback. No duplicate posts were generated.

`tools/promote-transport-rates-cpt.php` promotes all 11 new pricing dimension
fields from the legacy CCT into the existing CPT, yielding 37 JetEngine CPT
fields. Existing rate rows and historical amounts are unchanged.

WPML `transport-rates` switches from translation mode **1** to **2**:
translatable with original-language fallback when a translation is missing,
matching the existing `property-rates` setting. All 11 operational/commercial
fields are **COPY** in WPML; the existing Market Profile rate overrides
(`_booking_experience_transport_market_rates_v1`) and extras catalog
(`_booking_experience_transport_extras_v1`) are also **COPY**. The post
title/editorial description and `note` remain language-specific; `note`
continues to use WPML action **TRANSLATE**.

The system already has a market-aware quote owner in Booking Experience:
`transport_rate_candidates()`, `transport_rate_source()`,
`transport_rate_eligible()`, and
`Transport_Extras_V1::market_override()`. Their Market Profile pricing
depends on approved market context, not UI language. This promotion does **not**
create another market-pricing or checkout engine. The 11 newly copied fields
are authoring/readiness metadata only until the existing source owner's
revision-pinned rate approval/signature is explicitly extended to incorporate
any pricing-relevant new dimensions.

The new audit `/wp-json/mad4b-pricing/v1/transport-cpt-readiness` and the
existing **Tools → Transport Pricing Readiness** page summarize CPT field
coverage, translation configuration, market overrides, CCT migration references
and mismatched legacy rates.

As observed after migration:
- 5 CPT records: 2 published, 3 draft.
- 2 historic CCT records mapped to 2 existing CPT posts; zero duplicates.
- 5 translation groups, all currently English (not yet translated into ES
  or additional languages).
- 1 Market Profile rule stored across existing rates.
- 2 older CPT rows reference incompatible Mode/Type assignments and expired
  validity; both require review, and neither has signed price approval.
- Source CSV remains **candidate only**; currency and cost-vs-sale role remain
  unconfirmed. No source rows were imported as commercial rates.

Deployment/migration from inside the staging WordPress runtime:

```bash
wp eval-file tools/promote-transport-rates-cpt.php --allow-root
wp eval 'define("MAD4B_TRANSPORT_CPT_APPLY",true);require "FULL_PATH/tools/promote-transport-rates-cpt.php";' --allow-root
wp eval-file tests/transport-cpt-runtime.php --allow-root
```

The staging migration stores a before-image in the WordPress options table and
supports `MAD4B_TRANSPORT_CPT_ROLLBACK` if the schema and WPML settings have
not changed since migration. It leaves all rates, prices and bookings intact.

**Not yet done:** production rollout, translating the 5 Rate titles/notes to
other languages, resolving the two historical mismatched/expired rates,
supplier data approval, activating 2026 candidate rates, and wiring new pricing
dimensions into the signed commercial calculation path.

## Continued execution: market-aware display and supplier review (2026-11-19)

- The live Tours.dev staging version of Pricing Source Registry contained an
  extra child_ages accepted input in quote dispatch. The local branch now
  carries that live behavior forward; the staging file was not overwritten
  with an older Git copy.
- A display-only adapter in includes/transport-market-display.php replaces
  **only** the existing [transport_rate_catalog] shortcode registration.
  It delegates to the existing BookingExperience transport_rate_candidates,
  transport_rate_eligible, and transport_rate_preview providers, using the
  current WPML language and approved Market Profile context.
- The market-scoped quote is nonbinding, with one explicitly labeled price
  unit. Unapproved, expired, ineligible or provider-invalid rates display
  **On request**, without leaking an unapproved base amount or claiming stock.
  The existing checkout and tax authority remain unchanged.
- Source rates are visible as a review table under
  Tools → Transport Pricing Readiness, with city filters for Cairo, Aswan and
  Luxor. The source data is immutable and the table is commerce-admin only.
- Source audit verifies SHA-256 of the PDF, 51 operations and 210 price cells,
  Cairo column mapping (Luxury 7 seats = 1400; Hiace = 1600 on row 1),
  blank H1 values in Aswan/Luxor, and flags Luxor row 7's anomalous
  vehicle-price order and row 23's missing prices.
- Source currency and cost-vs-selling status remain unidentified. **No
  commercial rate was imported or approved from the PDF.**
- 16/16 hermetic tests for the new display adapter and 15/15 tests for the
  source matrix passed. PHP syntax and live staging homepage HTTP 200 passed.
- The reversible tools/quarantine-legacy-transport-cpt-rates.php script
  now provides strict dry-run/apply/rollback for historical rate post IDs
  66570 and 66571. It retains old CCT originals and Mode/Type metadata,
  and would only change published CPT status to Draft and stop_sale=true,
  after confirming CCT IDs, parent, mismatch, and expired validity.
- **Legacy quarantine is not applied** in this slice: staging WP-CLI
  reported STAGING_WPCLI_BUSY because two other CLI sessions were active.
  Do not bypass the concurrency gate or report these records as quarantined.

### Remaining supplier authority

Before commercial import/approval, obtain supplier-confirmed source currency
(do not infer EGP), cost-versus-sale role, valid end dates, route and
vehicle-category linkage, and corrections for the two Luxor anomalies.
Only then create deterministic per-vehicle Transport Rate CPT drafts and
submit them to the existing Rate approval and Market Profile policies.
