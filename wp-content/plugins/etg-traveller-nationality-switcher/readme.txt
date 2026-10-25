=== Travel Booking — Traveller Nationality & Group Management ===
Requires at least: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.5.4-local
License: GPLv2 or later

Reusable, site-neutral travel booking extension. QA Draft only; no paid Booking v2, departure inventory, or production certification.

## Canonical API
PHP classes: Travel_Booking_Traveller_Nationality_Switcher, Travel_Booking_Traveller_Management, Travel_Booking_Traveller_Nationality_Checkout_Blocks.
WordPress option: travel_booking_traveller_management_v1.
Group and departure post meta: _travel_booking_group_booking_minimum, _travel_booking_minimum_departure_participants, _travel_booking_departure_max_capacity, _travel_booking_departure_inventory_mode, _travel_booking_small_party_routing.
Presets: _travel_booking_traveller_preset_id.
Native shortcodes: [travel_booking_nationality_switcher] and [travel_booking_traveller_nationalities].
PHP extension hooks: travel_booking_traveller_management/verified_count, travel_booking_traveller_management/verified_service_links, travel_booking_traveller_management/certified_relationship.
Store API namespace: travel-booking-traveller-nationality.

## Configurable group minimum
In a Tour editor, under Traveller Preset, **Minimum Group booking size** may be blank. Blank automatically inherits the *first enabled Group Tour Tier's tier_min*, never a globally hardcoded number. Setting 12 for a tour changes group/private *recommendation* at 12, regardless of whether the first enabled tier begins at 10. The original Group Tier range still decides which tier can be applied for Group pricing; changing the recommendation threshold does not alter source pricing or taxonomy. An explicitly selected Group seat is never converted to Private solely because the customer's party is below the configured minimum. The separate, optional **Minimum to operate** sets a departure-level threshold and is unaffected.

## Optional inventory
Default: no inventory tracking. An optional capacity preview may inspect explicitly supplied participant counts and maximum seats. Missing counts stay UNKNOWN rather than zero; no seat availability or supplier confirmation is claimed.

## Checkout passport collection
The checkout count is derived only from verified server-side booking data, not WooCommerce Quantity. 20 is an input-page batch size in Checkout Blocks, not a commercial group capacity. Local QA validates 55 people (20+20+15) on Classic and Blocks. Existing Booking v2 preview remains capped by its own source and has no financial Checkout authority.

## Backwards compatibility and anti-branding policy
Historic WordPress activation filename and path are retained to avoid breaking plugin installations. Deprecated site-branded symbols, shortcodes, old session/cookie keys, filters, form fields and stored keys are read only via includes-legacy-compat.php. Canonical writes use neutral identifiers. An intentionally empty new meta field masks historical values without destructive DB migration. Old orders are not rewritten. The CI gate forbids adding site-branded prefixes into the canonical runtime files.

## Deployment gates
This plugin remains a QA Draft. No staging, production, live inventory, supplier reservation or paid checkout claims. Promote only after exact-commit CI and the project's established approval process.


## 0.5.3-local — suggested nationalities, explicit review and mode integrity
Traveller presets now include `suggestion`: `none`, `first` (default), or `all` (draft every person from signed booking-party preference). Every suggestion remains editable; a **Use preferred country for unfilled travellers** action never overwrites existing manual selections. Checkout Blocks requires the customer to deliberately confirm the entire booking-party list after the last edit. Any edit or cart identity/count change clears that acknowledgement. The server refuses complete but unconfirmed submissions; there is no implicit confirmation and no browser-only bypass.
A booked service with no configured Group/Private modes is **inquiry-only**: `request_status=pending_mode_configuration` and `booking_mode_verified=false`. No stock requirement is introduced. An automatic Group recommendation requires a matching Group Tier for its booking-party size; an explicit customer Group seat linked to an existing departure never becomes Private merely due to a small party.
The ISO2 validation catalogue is cached once per verification call to reduce work on large parties; WooCommerce remains master source for countries. WordPress Privacy Policy guidance explains stored per-traveller ISO2 order metadata and the nonpayment nature of drafts. No automatic erasure occurs, because order retention requires a separate merchant/legal decision. Legacy compatibility remains unchanged.


## 0.5.4-local — privacy export and read-only Store API caching
The WordPress/WooCommerce native privacy export for authorized order owners now includes all `Traveller n passport country (ISO2)` item metadata, including orders using HPOS. It uses the official `woocommerce_privacy_export_order_personal_data` extension hook; it does not create a separate exporter or expose PII publicly. The WooCommerce store's existing order retention and erasure policies remain authoritative. Manual deletion of individual confirmed traveller data is deliberately NOT automatic, pending the store's legal/operational policy.
Store API cart presentation caches linked-service identity within a request only when WooCommerce exposes a nonempty cart hash. It invalidates on cart-hash change; payment and order write paths always re-evaluate identity and verified quantities. In-memory test carts with empty cart hashes do not reuse the cache.
The `source`, `nationality`, and `max` preset fields must be understood as advisory configuration until a certified financial provider consumes them. They never create a verified booking, bypass passport requirements, or set supplier stock by themselves.
