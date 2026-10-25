=== ETG Traveller Nationality Switcher ===
Contributors: ETG, MAD4B
Requires at least: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.4.0-local
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
