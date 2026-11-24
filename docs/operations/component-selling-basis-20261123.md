# MAD4B unified currency and selling-unit inheritance — live, 23 Nov 2026

## Goal and ownership
Missing currency and sale unit on a Tour or a service component should inherit policy; missing prices, passenger quantities, original supplier cost currencies, and approvals must NEVER be invented.

Sale currency: explicit item > canonical WPML Tour source when applicable > valid linked parent > JetEngine Dynamic Pricing Controls (`etg-dynamic-pricing-controls_default_currency`, if populated) > WooCommerce store-base option. This is a currency-code fallback only, never a numeric FX conversion.
WooCommerce raw store currency is USD on both live sites. WCML's presentation currency on Tours.dev was EGP during testing; the source currency remains USD, with currency conversion controlled by the existing presentation layer.

Sale unit: explicit entity unit > parent/source WPML Tour unit > Dynamic Pricing Controls Tour Mode unit > component-kind unit > compatible company default > safe engine basis. Explicit Rate prices/units remain authoritative.

Hotel and Resort room/nights: per_room_night. Nile Cruise and cabin nights: per_cabin_night (actual property type or unit_kind=cabin). Tour, packages, activities/extras: per_person where otherwise unspecified. Transportation: no generic guess between per_vehicle, per_trip, per_day, per_person, per_cabin; choose via classified entity or Pricing Controls. Guides: per_guide_day. Native Woo product: per_item.

Supplier original cost currency cannot inherit the Woo storefront currency; it needs actual invoice/provider terms. No amount, inventory, taxes, staff costs or availability was generated.

## Implementation
New provider: wp-content/mu-plugins/013-mad4b-component-selling-basis-v1.php.
Additive native JetEngine Dynamic Pricing Controls: 8 component-unit selectors, plus read-only item metabox preview. Provider exposes resolve(id), resolve_external(kind,payload,scope), guide_language(term). Financial quote and Rate systems retain their separate commercial guards.

Thirteen existing live readers updated with a minimal 267-line patch: Form 6 preview/source, Direct/Rate source, Booking V2 eligibility and price, extras, Rate Generator draft preparation, Pricing Entry, legacy policy coverage and amount distribution, and the namespaced Commerce payment router. No direct postmeta write or new financial store. Fully qualified global class in the namespaced payment router fixed after a WP-CLI test exposed namespace resolution error.

Original runtime files on Tours.dev and Wayfatours were byte-identical before these changes. Backups and exact patch roundtrip proof in /opt/mad4b-package-checkpoints/2026-11-23-direct-sale-basis-policy-v1. All 14 updated live files (new provider + 13 readers) match both sites by SHA-256.

## Tests
Live acceptance source/Wayfa passed. PHP lint, direct source checks, unit/currency policy, WPML source-read inheritance, original Rate unit override, hotel room versus cabin nights, transport CCT read-only proposals, guide currency boundary, all Dynamic Pricing Controls field definitions, and existing Woo order/Rate safeguards were verified.

Form 6 nontransactional preview on Wayfatours with 2 adults and 2027-01-15:
- 57562 — Egypt Tour Packages: Best 8 Days Cairo, Aswan, Luxor & Nile Cruise: still blocked, start_price absent. Woo USD inherited, amount not invented.
- 60623 — Egypt Budget Tour: Cairo to Luxor Nile Cruise Discovery – 8 Days: USD 2580 (preview only).
- 60629 — Best of Egypt: Cairo, Luxor, Nile Cruise & Red Sea – 12 Days: USD 3560 (preview only).
All checkout_ready false, order_created false. Tours.dev preview remains blocked by existing separate source/Rate priority or package rules. Existing Booking Enabled flag remains false on both.

Rate Generator read-only prepare still applies real blocking conditions: 60629 Package stages incomplete, Steigenberger Alcazar 36084 Unit not assigned, transport 70830 Unit policy unclassified. No Rate create() called.
Steigenberger Alcazar 36084 room/night; M/S Blue Shadow I — Standard Cabin 78050 cabin/night. Original Property Rates 71529 source and 71972 Wayfa remain per_person where explicitly set. Guide Language Arabic 142 cannot auto-approve missing supplier currency.
Both sites HTTP 200 and TLS verified.

## Git scope and limitations
Branch feature/itinerary-overnight-native-stages-20261122 (PR 1046) receives the provider, the exact patch for thirteen source-backed readers, native acceptance tests, and this runbook. Most original reader files are not checked into the branch; recording a patch avoids replacing full live source with an outdated Git tree. Deployment is live on Tours.dev and Wayfatours only; not copied to Egypt Tour Gates or All Royal Egypt staging.
Neither authenticated browser GUI Save/Readback nor green GitHub CI nor PR merge is inferred from successful WP-CLI tests.
Rollback: reconcile concurrent changes, then selectively restore the 13 saved file preimages and remove only the new provider file; leave unrelated Rates, Tours, CSS and Git branches untouched.


## 24 Nov 2026 — Currency fallback extension

The live read-only Component Selling Basis now reads the existing JetEngine Dynamic Pricing default currency before WooCommerce's base. The policy option is currently **unset** on both sites, so the effective sale currency for Tour #57562 is **USD** inherited from WooCommerce. Its price remains absent; inheriting USD does not create or approve a selling amount, an external supplier cost or a payment intent. Explicit Tour #60623 and #60629 currencies remain USD.

Confirmed with six no-write cases on both WordPress sites: empty Dynamic setting → Woo USD; Dynamic override EUR → EUR for Tour #57562; explicit Tour USD wins; invalid Dynamic code fails closed; supplier currency remains unverified; existing baseline matches. Existing WCML market FX and native WooCommerce store currency were not modified.

Runtime file SHA256: `09653e4ec7b761e2b7d1257afd11fd63d4c8d458310870c885a17f813661272c`. VPS report: `/opt/mad4b-company-provisioning/reports/form6-currency-and-payment-guard-20261124/manifest.json`. A prior backup of the exact live PHP exists under `/opt/mad4b-backups/currency-fallback-dynamic-20261124/`. Release approval and production Git deployment gates remain independent.
