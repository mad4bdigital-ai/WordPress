=== ETG Traveller Nationality Switcher ===
Contributors: ETG, MAD4B
Requires at least: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.5.1-local
License: GPLv2 or later

Local Lab prototype — not certified for public release.

Shortcodes: [etg_nationality_switcher] for an explicit preferred passport nationality; [etg_traveller_nationalities count="2"] for 2 independent traveller passport country select fields (1–20).
Country list comes from WooCommerce Core (with independent AJAX fallback); flags reference WPML native SVGs and gracefully degrade to ISO2 when unavailable. No country or flag assets are bundled.

Classic WooCommerce hooks validate each submitted nationality in etg_traveller_nationalities[0..N-1], attach to cart items and copy into Woo order item meta using ISO2. Fluent Forms validates the explicitly named fields etg_traveller_nationalities and etg_passport_country. Does not change customer billing/shipping, Market Profile, WCML currency, Woo product price or tax.

Privacy: signed, HttpOnly, SameSite=Lax first-party 90-day cookie stores only ISO2 preferred nationality, no passport numbers, IP address, identity, or travel documents. Separate nationality per traveller lives in the booking/order record, subject to site privacy retention.

Native Checkout Block entry and vendor booking-field auto-binding have not been certified; explicit shortcodes/form mapping required. Do not claim production readiness or enable on staging/production without acceptance.

== Checkout Blocks 0.2.0-local ==
Native WooCommerce Checkout Block integration uses official Store API CartItem extension `etg-traveller-nationality`, the `ExperimentalOrderMeta` slot and `setExtensionData` on the WC checkout store. Each eligible tours-and-activities or tour-rates line gets one passport country field per traveller (quantity by default; filter `etg_traveller_nationality/required_count` for booking-specific counts), max 20 per line. Checkout POST validates every cart line before adding order line-item ISO2 nationality meta; unrelated regular products do not get nationality fields. Missing/stale/invalid data is denied with HTTP 400; WCML currency and product prices remain unchanged.

WooCommerce native Store API and both desktop/mobile React fields passed simulated checkout tests, not a real-payment transaction. LiteSpeed is excluded from concatenating scripts only on pages containing a checkout block, to preserve Woo core initialization order. Public checkout remains Classic. Staging/Production and a real gateway checkout remain pending.

## 0.3.0-local
Checkout Blocks derives passport-nationality field count from the server-validated shared_booking_configuration.quantities.persons on Tour Rate cart items. A Booking v2 preview-only identity or plain Woo Quantity cannot authorize checkout. Missing/invalid booking config fails closed. Max 20 travellers per line.

## 0.4.0-local
Unified Traveller Management includes optional presets and per-product overrides, verified source resolver, in-memory booking-scoped registry and trusted linked-service contract. No Woo unit/pricing/currency or paid booking authority changes. Unverified accommodation and transport shadow quotes do not authorize checkout.

### Traveller Management 0.4.0-local usage
Open WooCommerce > Traveller Management. Built-in presets can be customized, cloned, reset or selected as the default. Set a per-post preset from the Traveller Preset meta box of supported tour, rate, property or transport CPTs. Presets never independently authorize checkout payment or change WooCommerce price/currency. An explicitly verified booking contract is required for each checkout cart line.

A verified optional service can inherit a subset of travellers from its main booking through etg_traveller_management/verified_service_links, a server-only PHP hook. Do not accept these bindings from user-submitted cart metadata. The default Checkout maximum is still 20 verified people per booking line. No general passport or immigration authority is inferred from country-market settings.


## 0.4.1-local — Group Tour Tiers capacity
The optional `Group Tour Capacity Policy` field supports `taxonomy_when_group` (default for Tour and Automatic) and `booking_engine_only` (opt out of an additional Traveller Management tier check). Only explicitly `group` bookings use the site's existing `group-size-tiers` taxonomy, selecting exactly one enabled tier that contains `quantities.persons == group_size`. Disabled, missing, overlapping or mismatched tiers fail closed. Private bookings, accommodation, transport and regular WooCommerce products are not assigned Group Tour tiers. No new taxonomy, pricing rules, currency source or shopping cart entitlement is created.

`ETG_Traveller_Management::assess_v2_preview($configuration)` validates the native Booking v2 configuration first and provides a nonfinancial capacity verdict; e.g. 25 passengers in an enabled 21–30 tier pass the group-policy preview but `checkout_capacity_certified=false` because Checkout remains QA-certified for up to 20. Existing Booking v2 itself still caps persons at 50, independently from the tier maximum. When a certified Booking v2 adapter is provided in future, `resolve()` rechecks explicit group mode, product identity, tier selection and group-size count. It never trusts Woo Quantity as person count.


## 0.5.0-local — distinct booking parties and departure groups
**New design supersedes all previous references to a 20-person Checkout limit.** Twenty is now only a front-end Blocks page size. Server-verified `shared_booking_configuration.quantities.persons` may be as large as the *existing* Booking Rate native range 1–999; 55-person Local Lab quotes, Classic and Blocks validation and in-memory item metadata are covered by regression tests. Woo cart `quantity=1` remains the composite booking quantity. Store API payload is validated for the full traveller array; no skipping unfilled pages.

Business semantics: `booking_party_size` counts seats purchased by this customer; `departure_total_after_booking` counts this party plus independently confirmed guests on the same planned departure. `first_group_minimum()` finds the first enabled Group Tier minimum; this is NOT a per-customer minimum. `advise_booking_mode()` recommends Private for a below-first-tier request only when it is offered and no Group departure has been explicitly selected. An explicit Group seat is never silently turned into Private, including single-seat booking. Per-tour `_etg_small_party_routing` can disable Private recommendations.

`assess_departure()` checks optional per-tour `_etg_minimum_departure_participants` and `_etg_departure_max_capacity` plus separately provided confirmed counts. It produces *preview-only* capacity and minimum-to-operate evaluations; no source currently certifies live departure inventory or the aggregation of confirmed bookings. Admins can edit these settings alongside Traveller Preset in the tour CPT editor. A below-minimum departure remains Group pending sufficient guests, not Private.

**Important limits:** Booking v2 independently still supports up to 50 persons and requires `group_size=persons` in its preview schema; multi-booking group departures have not been bridged to Booking v2 financial Checkout. The Passport Checkout plugin successfully handles 55 verified persons in its tested Tour Rate v1 path, but no paid financial order, active departure seat reservation or multi-booking inventory locking has been approved. The 999 schema boundary is not a promise that every checkout or supplier supports a 999-person booking. Production/Staging rollout remains blocked pending broader acceptance.


## 0.5.1-local — Inventory not required
Departure inventory is **OFF by default**. Per-tour `_etg_departure_inventory_mode` defaults to `untracked`; there is no stock, manual seat count or availability requirement to submit a booking request. The optional `capacity_preview` mode uses operator-specified caps and caller-supplied known counts **only for advisory checks**. A missing occupancy count is UNKNOWN, never zero. No fake "seats remaining", no stock holding and no availability guarantee. The system can accept a booking *request* without claiming a confirmed supplier allocation.

Settings are **already available** in the Tour CPT's **Traveller Preset** metabox: Inventory handling, optional Minimum to operate, optional Maximum seats, Small-party routing. They are registered WordPress post-meta keys, not new JetEngine field groups. Empty fields are permitted and do not create a stock restriction. Other travel services and pricing/tier taxonomies remain untouched.

The optional `assess_booking_request()` contract returns a request-admission preview, not a payment or reservation confirmation. `assess_departure()` permits null confirmed counts and null capacity while preserving strict numeric checks on supplied values; explicit maximum+count comparisons are advisory only. Existing Group Tour Tiers are independent of stock and may still be used for pricing/group policy where configured.
