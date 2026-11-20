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

## Operation classification proposals (source-to-rate preflight)

Added a standalone proposal map for all 51 PDF source operations, keyed by
Cairo/Aswan/Luxor plus source row number. Every classification is marked
proposal_only, never an approved route, rate or sale price. The map uses
existing service-kind values already registered on Transport Rates.

Specific ambiguous rows are deliberately NOT converted to a single route:
Cairo 9 (Alexandria / Sokhna), Cairo 10 (Ismailia / Fayoum), Cairo 12 and 13
(alternative destinations), Luxor 14 and 15 (direction/return unspecified),
and Luxor 23 (Dendera + Hurghada overland with all prices blank).
The review table shows the proposed service type and source-specific notes.

The proposal file is private PHP data guarded by ABSPATH; no new CPT posts,
Rate prices or inventory were created. A further 3 source audit tests assert
complete mapping coverage and preservation of ambiguous routes, bringing the
source-audit result to 18/18. Post-deployment staging PHP lint passed,
homepage remained HTTP 200, and unauthenticated source REST remained 401.

## 19 November 2026 — PDF Transfer Enrichment Across Active Languages

The original 3-page supplier file, Transportation 2026 - Sheet1 (2).pdf,
SHA-256 c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd,
is the source of 51 operating rows (Cairo 13, Aswan 15, Luxor 23) and 210
nonempty raw price cells. Cairo vehicle headers are Limousine, Luxury 7 seats,
Hiace, Coaster, Bus. The Aswan/Luxor header is H1, not Luxury 7 seats, and its
column contains no prices. Aswan/Luxor rows say before 5% discount, not an
approved net discounted selling price. Luxor row 7 has anomalous vehicle
price order; row 23 has all prices blank. All existing source numbers are
preserved with their original Arabic wording, without guessing units/currency.

### Actual site language matrix

- Tours.dev: en, ar, fr, de, es, it, as returned by WPML live configuration.
- Wayfatours: en, es, as returned by WPML live configuration.

A data-only localized source contract is saved in
data/transport-pdf-2026-localized.php. The reviewed editorial translations
originate from tools/transportation-fixtures/transportation-2026-editorial-locales.tsv;
the Arabic text is the original source statement. Edits to translations do not
rewrite the source PDF. Every draft records city, source row key, original Arabic,
provisional kind, route-review notes, per-vehicle source prices and flags.

### Draft-only content

The site-scoped, restartable WPML importer at
tools/import-transport-pdf-operations-wpml.php created or reconciled
51 source-operation translation groups per website:

- 306 Draft Transportation posts on Tours.dev: 51 per en/ar/fr/de/es/it.
- 102 Draft Transportation posts on Wayfatours: 51 per en/es.

Every language variant has exactly one source key and one WPML translation
group, and an appropriate localized Road term and origin-city term. Vehicle
class-specific attachment IDs are stored as metadata, NOT promoted into a
guaranteed featured fleet vehicle. Crucially, no public post, Transport Rate
sale amount, starting price, WooCommerce order, or booking approval is created.
The commercial source role and currency are unknown. No arbitrary capacity,
origin/destination coordinates, or contract end date was invented.

A temporary simultaneous importer collision created 11 duplicate Drafts
relating to Aswan:13 and Aswan:14; they were quarantined into WordPress Trash,
not permanently deleted. Original canonical source operation groups were
retained. An atomic per-site WordPress option lock was added for future imports.
A full import replay now returns 306/306 and 102/102 existing posts with zero
new duplicates.

### Vehicle class illustrations

Five source-licensed Wikimedia Commons files per site were imported into
the private image library for the PDF vehicle categories, and six logical
vehicle classes were mapped to the five image attachments:
Limousine, Luxury 7 seats, H1, Hiace, Coaster, Bus. Luxury 7 seats and H1 may
share a *reference illustration*, but are separate source pricing columns.
The photos represent the general vehicle category, not the supplier's vehicle
make, seating, luggage or guaranteed fleet. No image is auto-attached as a
commercial tour hero.

The preserved attachment metadata includes Commons original page, creator,
license class, license URL, resized-file hash and localized ALT text in all six
available editorial languages. Licensed files include CC BY-SA 4.0 and CC BY
2.0; these terms are distinct and are preserved per attachment. The existing
photo-credit footer is now translatable in all active languages and uses
language-specific attachment ALT text when available.

### Admin review surface and API

Both sites provide Tools -> 2026 Transfer Source Review, linked from the
existing Dynamic Pricing Controls admin page. It includes locale and city
filters, vehicle category reference imagery, origin/row, translated operation
titles, original Arabic, source-only raw values and review flags. It links to
the exact WPML Draft for editing, but it does not publish any price.

Authenticated read-only API:
GET /wp-json/mad4b-pricing/v1/transport-pdf-2026-review?city=Aswan&lang=es
Commercial/admin permissions are required. Anonymous GET returned HTTP 401
on both sites; authorized calls returned HTTP 200.

### Runtime verification

- Tours.dev: 381/381 assertions passed; 306 drafts, 6 languages, 51 groups.
- Wayfatours: 141/141 assertions passed; 102 drafts, 2 languages, 51 groups.
- In each site: 5 physical licensed images map to 6 category keys, source
  price cells remain unverified, H1 price column stays empty, Luxor:23 is
  unpriced, and the historical expired rates remain Draft.
- Both public homepages returned HTTP 200 after installation.

### Still requiring business data

Supplier confirmation is indispensable for exact vehicle make/fleet imagery,
seating and luggage limits, currency, cost-versus-sale amount, discounts,
contract end dates, alternate destination choice, and any inclusions such
as guide services, fuel, parking or entrance tickets. These cannot be inferred
from Wikimedia photos or public web searches. Commercial pricing approval and
publishing remain a separate governed operation.

## 19 November 2026: trusted PDF + self provider + native transfer controls

The user designated the originally uploaded Transportation 2026 - Sheet1 (2).pdf as the trusted
operational pricing source and each website's company as its own self provider.

Original PDF SHA-256:
c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd

The contract preserves 51 operation rows, 210 raw source values and effective start 2026-04-01.
The PDF does not disclose its monetary currency or the commercial nature of the amounts
(cost versus selling price). Do not automatically use WooCommerce's default USD as source
currency, invent an end date, or activate a payable quote from the source proof alone.

Site-scoped provider assignments:
- Tours.dev: 306 already translated source operation Drafts, all provider_id self.
- Wayfatours: 102 already translated source operation Drafts, all provider_id self.
- Each site independently persists the trusted self-source control policy, versioning,
  write history and original source hash. Metadata updates do not change product prices.
- The original source numbers, five original Transport Rate posts on Tours.dev,
  four original Wayfatours Transport Rate posts, and existing bookings are unchanged.

Native Dynamic Pricing Controls extension is loaded within:
  /wp-admin/admin.php?page=dynamic-pricing-controls

The existing Pricing Source Registry plugin enqueues the new transfer panel in JetEngine's
native page, like the existing Accommodation Pricing accordion. This is NOT a separate
rates engine, a duplicate JetEngine options page or an alternate checkout route.

The controls include:
- City: Cairo, Aswan, Luxor.
- Active WPML language, localized source operation and original Arabic statement.
- PDF source vehicle class with an illustrative licensed photo and raw input value.
- Quantity of vehicles and a nonbinding self-source price simulation.
- Explicit, per-site source currency and source role (reference / self cost / self selling).
- Optional application of the PDF's 5% reduction for Aswan and Luxor only.
- Global, per-city, and per-vehicle-class markup percentages.
- Direct links to the native Transport Rate CPT, source Draft review and Market Profiles.
- Read-only native Market Profile approved count. No parallel currency or market engine.

Formula in preview:
  raw * (1 - documented_discount_percentage/100)
      * (1 + (global_markup + city_markup + vehicle_markup)/100).
The original PDF amount is never overwritten. No source-blank cell becomes zero.
Aswan/Luxor H1 prices remain absent; Luxor row 23 remains fully unpriced.
Luxor row 7 original anomalous values are preserved and flagged.

Server owner: MAD4B_Transport_Self_Pricing_Controls_V1 in transport-self-pricing-controls.php.
The AJAX handler checks pricing capability and nonce. Policy saves validate bounded
financial percentages and allowed currencies, use optimistic revision matching and
short-lived per-site write lock, preserve a change history, and check persistence.
The native Booking Experience and Market Profile engines retain price approval,
currency conversion, tax, availability, and checkout authority.

Wayfatours CPT parity:
- Source Tours.dev Rate CPT had 37 JetEngine fields, Wayfatours only 26.
- Wayfatours has no legacy transport_rates CCT. Nothing was copied from a non-existent CCT.
- Site-specific reconciliation from Tours.dev's exact 11 field definitions moved the
  production CPT to 37 fields without changing rate rows or price values.
- Wayfatours Transport Rates WPML mode now 2, with 13 new technical/market metadata
  keys set to WPML COPY across translations, matching Tours.dev.
- A verified rollback backup was stored in the Wayfatours options table.

Verification:
- Source/self provider runtime: 35/35 on Tours.dev and 35/35 on Wayfatours.
- Localized source matrix previously verified: 381/381 on Tours.dev and 141/141 on Wayfatours.
- Two admin controls history readbacks succeeded, revision conflict rejected, concurrent
  write lock rejected, original provider/source hash unchanged.
- WordPress admin script, CSS, nonce and HTML template were found on both sites;
  script/style URLs HTTPS, JavaScript parsed with Node.
- Public homepages and static admin assets returned HTTP 200 on both sites.
- Authenticated AJAX catalog returned the localized source.
- Aswan row 1 Limousine raw 430 becomes 408.50 after the PDF's 5% reduction;
  isolated +17% total markup simulation gave 477.95 per vehicle and 955.90 for two.
  No stored markup was changed for this calculation.
- Source role and currency remain explicitly unconfigured on both sites.
- Approved native Market Profiles count was 0 on both sites when checked.
- No commercial rate approved, no checkout authorized, no posted price or order mutated.

Next commercial step: configure the PDF's actual currency and whether its values are
company self-cost or self-selling base in the new admin panel. Those facts are not in
the source document. Native Transport Rates require separate price approval before sale.

## Nov 19 2026 — Transportation Tour Component Status and Tour-style Pricing UX

### Hotel parity and eligibility

The Property CPT field prop_use_in_tour_rate_generation is the existing
Yes/No Glossary 321 source for automatic Tour Rate eligibility. Transportation
now mirrors it as trans_use_in_tour_rate_generation, a JetEngine radio field
with default No, inserted immediately below trans_description (index 11).
The Transportation CPT has 70 fields on Tours.dev and Wayfatours, and the
status metadata is WPML COPY across all active languages.

Existing statuses initialized to No: 345 transports on Tours.dev, 131 on
Wayfatours. All historical rate prices, publication statuses and bookings
remain untouched. The separate trans_available_status is unchanged.

The eligibility adapter in includes/transport-tour-component-eligibility.php
requires a Yes opt-in, published post, operational availability, company
Tour Component channel and native mode/type verification. The resulting
component qualification is NOT a rate, stock or checkout authorization.
It gates native Tour Rate related options immediately before visual-options
composition (filter priority 25), and the known native Tour transport listing
queries 258, 259 and 260, without filtering unrelated queries. Read-only
status and blockers are available through a permission-protected REST route.

### WPML identity correction

The original 2026 PDF draft importer wrote localized term IDs to trans_mode
for non-English translations. Native Tour Components require canonical
transport mode 568 and the original taxonomy relation. The guarded exact
source migration normalized 255 Tours.dev and 51 Wayfatours localized Drafts,
leaving English originals and taxonomy relations unchanged. All 408 source
Drafts now use canonical Road mode 568. WPML trans_mode is COPY and future
importer writes canonical 568. Both sites retain separate reversible
metadata backups. No unverified subtype, supplier capacity or prices were
created. Current source Drafts remain ineligible until validated.

### Native UX revision

The existing Dynamic Pricing Controls page now follows the Tour Rate pattern:
1. Choose a transport (city, WPML language, searchable PDF row, Tour Component
   qualification filter, vehicle and quantity).
2. Configure the company self-provider source policy (currency, amount role,
   source-specific discount and global/city/vehicle markups).
3. Review the saved-policy price preview and qualification reasons on a
   sticky desktop right-side rail, with an edit link straight to the native
   Transportation Status field below Description.

Draft policy edits are labeled as unsaved and preserved across city/language
filters; previews always use persisted policy. AJAX responses are
sequence-checked so out-of-order calls cannot show stale source selections.
Layout stacks at tablet/mobile widths; nonce and capability checks are
unchanged. No separate pricing engine or alternate checkout was created.

### Verification and rollout boundary

- Tours.dev: 27/27 new status and Tour Component tests; 381/381 existing
  multilingual PDF-source tests; 35/35 self-price control tests.
- Wayfatours: 27/27 new status/component tests; 141/141 existing source
  tests; 35/35 self-price control tests.
- Source price data and authorization were not changed.
- JavaScript passed Node syntax checks. HTML template passed structural and
  unique control-binding checks; registered admin assets were verified on
  both sites. Both live sites are still serving the new UI assets.
- The source catalog response assembled 51 rows in roughly 75–228ms
  in CLI samples, with no internal :8080 links.
- All 476 transportations currently retain Status No and no Tour Component
  was autoenabled.
- No authenticated graphical browser was available for pixel-level,
  keyboard navigation and viewport screenshot acceptance; that remains
  separate from verified server behavior.

## November 19 2026 — Owner-authorized PDF transport component opt-in

The owner explicitly authorized setting Use in Tour Rate Generation = Yes
for all transportation operations added from the trusted 2026 PDF, across
all active WPML languages on both sites, for future regenerated Tour Rates.

Tool: tools/enable-pdf-transport-tour-components.php

- Tours.dev: 51 supplier operation keys × six languages = 306 Drafts Yes.
- Wayfatours: 51 source operations × two languages = 102 Drafts Yes.
- All 408 Draft posts were preflight-verified against the exact trusted
  source SHA-256, company self provider, WPML translation group, canonical
  Road mode 568, source non-sale marker, and Draft post status.
- Each site has a persistent before-image option
  mad4b_pdf_transport_component_yes_backup_20261119_v1 and guarded rollback.
- The future PDF importer now sets trans_use_in_tour_rate_generation=1 on
  newly created source Drafts. The general Transportation CPT default
  remains No for unrelated transport services.
- Excluded QA-only 67115/67117 and unrelated VinFast 39707: these are
  outside the trusted PDF source; they need independent verification.
- Eligibility is independently gated: these records are opted in but are
  NOT commercially qualified while remaining unpublished Drafts.
- All 408 remain Draft. No Transport Rate amount, publication status,
  approved Market Profile, inventory, WooCommerce order, or checkout changed.
- Read-only runtime tests after apply: 32/32 on Tours.dev and 32/32 on
  Wayfatours, checking exact PDF language rows and source-only Yes.
- Reversible per-site state is saved before the status changes.

Future Tour Rate regeneration may now use these operations as explicit
composition candidates, subject to publication, validated availability,
route/type checks and native Rate approval before customer offers.

## 19 November 2026: Route-aware Transportation candidate generation

### Original PDF and confidence
The exact original Transportation 2026 - Sheet1 (2).pdf (SHA-256
c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd)
was reopened. It contains 51 Cairo/Aswan/Luxor source operations, but does not
identify every pickup/dropoff point or a definitive monetary currency.

The 51-key evidence fixture data/transport-pdf-2026-route-evidence.php
preserves source origin city, any explicitly named destination,
round-trip/multi-stop evidence, and ambiguous alternatives. No supplier
addresses, station IDs or geocodes were invented. Cairo:9 contains
Alexandria and Ain Sokhna alternatives and is review-only. Luxor:23 remains
unpriced and review-only. Source Luxor:7 price anomaly is preserved.

### Reversible source route annotations
The site-guarded migration tools/annotate-pdf-transport-geography.php
annotated 306 Tours.dev and 102 Wayfatours source Drafts. Origin
evidence, possible destination slugs, route shape, WPML COPY and
point-confirmation flags are now stored on each localized source post.
Single explicit destinations were grounded for 72 Tours.dev and 24
Wayfatours records (96 translated posts). Every pickup/dropoff point
remains marked unverified. Native pickup/dropoff allowed-destination
fields, station IDs and existing prices were not changed. Each site
retains a before-image under option
mad4b_pdf_transport_route_annotations_backup_20261119_v1.

The first post-write readback encountered a WordPress meta type difference
(int expected / string returned). The idempotent retry normalized scalar
comparisons and fully validated the already-saved annotations on both sites.

### Native Tour Rate authoring
The native JetEngine Tour Rate schema was expanded on both sites from
46 to 52 fields immediately after Accommodation Stages:
- has_transportations, default No;
- transport_assignment_mode, manual or auto-suggest;
- transport_selection_mode, simple OR or multiple stages;
- related_transportations_id, simple alternatives;
- transport_price_policy, Inquiry/Included/Additional;
- _mad4b_transport_stages_v1, itinerary repeater with stable key, day,
  origin/destination taxonomy term IDs, return flag, required flag,
  within-stage OR/AND, category, price policy and selected transport IDs.

Required stages combine as AND; alternatives within stages combine as OR,
or as AND where two distinct services are necessary. New native metadata
is WPML COPY; existing Tour Rates remain disabled and unchanged.
Original Accommodation Stages and their transport_ids were not migrated,
priced again or deleted. Per-site schema rollback option:
mad4b_tour_rate_transport_stage_schema_backup_20261119_v1.

### Admin route suggestions and API
The read-only route engine includes/transport-tour-rate-routing.php
implements protected POST REST route:
  /wp-json/mad4b-pricing/v1/transport-tour-rate-candidates

It requires an explicit stage origin/destination, Status Yes, self provider,
source-grounded forward route and a validated OR/AND stage specification.
It returns separate city-level Draft candidate and route-review queues,
with an exact source key, localized title, reason codes, and no price
approval or checkout grant. Reverse routes are rejected. Round trips need
the explicit return flag. Multi-stop round trips require editor review.

The companion native Tour Rate meta box under
includes/transport-tour-rate-routing-admin.php previews stage matches
and blockers while editors save permanent values in JetEngine's
own Tour Rate fields. JS/CSS and REST nonce are loaded only in
authorized Tour Rate editing pages, with HTTPS asset URLs.

### Verification
- 47/47 route/source/OR-AND/WPML/taxonomy assertions passed on Tours.dev.
- 47/47 assertions passed on Wayfatours.
- Aswan to Luxor returns Aswan:7 and Aswan:8; reverse direction returns
  Luxor:18 and Luxor:19. Cairo to Hurghada returns Cairo:11.
- Cairo to Alexandria returns Cairo:9 in review only, not automatic matches.
- Abu Simbel round-trip requires the return flag; Edfu + Kom Ombo
  multi-stop stays in review instead of being selected automatically.
- All six active Tours.dev and two Wayfatours WPML languages return
  the corresponding locale-specific posts for Aswan to Luxor.
- Authenticated REST and native Tour Rate editor meta box, nonce,
  stylesheet, script and HTTPS URL registration were tested on both sites.
- No existing Tour Rate post, source price, booking, or WPML relation
  was modified by candidate generation. 408 source Drafts remain opted in
  Yes but unpublished and commercially unqualified.

The new system is usable for draft Tour Rate stage composition. It is
not a bulk regeneration of old Rates or commercial-price activation:
verified pickup/dropoff points, vehicle capacities, monetary source
currency and approved native Transport Rates remain separate requirements.

## 20 November 2026 — Editorial publication of trusted PDF transport services

Owner instructed publication of the Transportation CPT entries imported from
Transportation 2026 - Sheet1 (2).pdf, without inventing unverified selling
prices, fleet photos, capacity, pickup/dropoff addresses or booking authority.

The exact-source, reversible publisher is:
tools/publish-pdf-transport-editorial.php

The tool verifies complete source groups and active WPML language coverage,
trusted PDF SHA256, provider self, Road category, non-sale metadata and
Use in Tour Rate Generation = Yes. Batches are source-key-scoped. It records
a per-site before-image backup in the WordPress option
mad4b_pdf_transport_editorial_publish_backup_20261120_v1.
It does not update Transport Rate amounts, trans_start_price, trans_type,
trans_available_status, WooCommerce checkout or company Service Activation.

Localized content, translated description/excerpt and Rank Math noindex/follow
were added to every source post. The editorial copy explains that precise
pickup/dropoff, vehicle availability, itinerary and supplier quote still
require confirmation, with no direct online booking. Company provider remains
self. Generic Wikimedia vehicle-category photos are NOT published as featured
supplier fleet photos: the media manifest explicitly does not authorize
that use.

### Verified status

- Tours.dev: 306/306 source posts Published, 51 × EN/AR/FR/DE/ES/IT.
- Wayfatours: 102/102 source posts Published, 51 × EN/ES.
- Combined: 408 editorial-Published, 0 source Drafts, all opt-in Yes.
- No original supplier price made a sellable rate. All 408 retain non-sale
  metadata and remain not qualified for checkout.

WPML syncs post_status across translations as soon as one language publishes;
this can temporarily leave a sibling translation with the original Draft
text despite Published status. The publishing tool matches that exact case
against its backup, finalizes the localized editorial copy and checks every
post's publication marker, status and non-sale conditions. Raw post_status
alone is never used as the completion signal.

### Site-wide visibility is separate from publication

Both sites currently use transportations_channel_mode=tour_only.
The presentation service-visibility hook intentionally returns HTTP 404
on standalone Transportation archive/detail URLs under this policy.
Changing the company mode to standalone_and_tour would expose other
previously published non-PDF transportation records, so it was deliberately
NOT changed. Published records exist in WordPress and may be composed
internally for Tours subject to eligibility; they are NOT accessible
as independent guest-facing Transportation pages yet.

### Source reviewer and regressions

The protected 2026 Transfer Source Review now recognizes both Draft and
editorial-Published source posts, provides their current editorial status,
and maintains 51 rows per active language. Its supplier price values
remain internal to authorized pricing staff. The future importer recognizes
existing marked Published source posts rather than trying to recreate them.
All existing source/component/route/self-pricing tests now distinguish
source publication from commercial authorization.

A new read-only test at tests/transport-pdf-publication-runtime.php checks
the exact source SHA, 51 WPML source/TRID groups, all localized records,
editorial content and marker, noindex, supplier provenance, absence of
selling price/availability/fleet attachments, and unchanged tour_only mode.

Verified checks on the fully published live state:
- Tours.dev: 133/133 publication, 381/381 PDF/WPML,
  32/32 Tour Component, 47/47 route, 35/35 Self Pricing.
- Wayfatours: 121/121 publication, 141/141 PDF/WPML,
  32/32 Tour Component, 47/47 route, 35/35 Self Pricing.

No commercial Rate generation or checkout activation occurred.

## 20 November 2026 — Pickup/drop-off, supplier readiness and Form #6

Owner scope: cover pickup/drop-off, pricing and availability without opening
standalone Transportation pages. The trusted original 2026 PDF lacks confirmed
currency, cost-versus-selling-price role, date-specific stock, actual fleet
capacity and exact pickup/dropoff addresses. These remain explicit blockers,
not guessed data.

### Source of truth

Transportation and the Tour Rate define stage city, direction, service kind,
transport alternatives, required stages and OR/AND. Stage point descriptors
hold operator-specified hotel/station references and meeting instructions.
A native post ID proves its identity only; it does not prove a supplier pickup
agreement, distance, driver assignment or exact meeting time.

Fluent Form #6 records requested traveler logistics. The original commerce
pickup module retains hotel pickup booked/not booked, name, city and address.
The new drop-off fields store the final requested destination, not a new
transfer Rate or automatically accepted route. Native Transport Rates remain
the only source of approved transport price in a documented currency and
unit. Availability is On Request until verified for a travel date.

### Additive native Tour Rate stage fields

The reversible tool tools/reconcile-tour-rate-stage-points.php added six
fields in the existing JetEngine Transportation Stage repeater, raising its
subfield count from 11 to 17 while keeping 52 parent Tour Rate CPT fields:

- pickup_point_type and dropoff_point_type
- pickup_point_ref_id and dropoff_point_ref_id
- pickup_point_notes and dropoff_point_notes

Location kinds include pending, hotel, airport, rail_station, marina,
meeting_point and custom. Reference IDs are optional native Properties or
Stations post identifiers. All new location types default Pending. The
route-candidate parser now accepts these fields and native JetEngine 1/0
boolean representations while preserving supplier_points_confirmed=false.
No existing Rate post content, pricing or stage values were changed.
Rollback option: mad4b_tour_rate_stage_points_schema_backup_20261120_v1
on each website.

### Form #6 native final drop-off

The site-guarded migration tools/reconcile-transport-form6-dropoff.php
added four optional fields directly below the existing pickup hotel fields:

- mad4b_dropoff_mode: same-as-pickup if route permits, hotel/cruise,
  airport, railway station, port/marina, meeting point or confirm later
- mad4b_dropoff_place_name
- mad4b_dropoff_city
- mad4b_dropoff_address

The original Form #6 (Single Tour Order Form) has 22 top-level fields
on both sites instead of 18, with all existing field definitions preserved.
Each site retains a separate before-image and guarded rollback at
mad4b_form6_transport_dropoff_backup_20261120_v1.

The additive includes/transport-form6-dropoff.php and
assets/transport-form6-dropoff.js render them conditionally only on an
eligible published Tour page, based on the existing pickup policy or a
published Tour Rate with Transportation explicitly enabled. Visible labels
are localized across EN, AR, FR, DE, ES and IT.

The new submission filter runs at priority 55, after the existing native
pickup filter at 45 and pricing intent filter at 25. It reuses the original
Fluent Form #6 Tour/Rate binding, embedded Tour context and server-created
nonfinancial booking intent. It appends a separate
_mad4b_transport_dropoff_v1 logistics record into the SAME Fluent Forms
response without changing the original price, Rate, checkout flag, order or
booking workflow. Same-as-pickup reuses previously supplied hotel details
only as an unconfirmed traveler request. Missing details remain Pending.

### Operations readiness dashboard

The self-provider Dynamic Pricing Controls catalog now includes
includes/transport-source-operations-readiness.php and an operational
readiness section with: source city/destination evidence; exact pickup
and dropoff flags; supplier availability; source currency/price-role
status; approved native Rate presence; fleet capacity confirmation;
and Form #6 traveler handoff. The response stays nonfinancial.
The 51 PDF source services remain On Request / Reference Only.

Existing transportations_channel_mode remains tour_only on both sites:
standalone Transportation pages and archives are NOT made public.

### Verified tests

- Form #6 source-bound integration: 15/15 Tours.dev and 15/15 Wayfatours.
- Operational readiness: 24/24 Tours.dev and 12/12 Wayfatours.
- Routing/OR-AND including 6 stage-point fields: 51/51 on both websites.
- Real public Tour pages load Form #6, four optional drop-off inputs and
  scoped JavaScript successfully (HTTP 200).
- No actual traveler Form #6 submission or Woo order was created for tests.
- Supplier price currency, availability, capacity and exact route points
  have not been certified: checkout_authorized remains false.
