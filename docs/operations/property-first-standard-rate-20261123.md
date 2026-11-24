# MAD4B Property First + Standard Base Rate — 23 November 2026

## Decision and scope
The user currently wants Property-level Hotel / Nile Cruise choices, with no specific room/cabin required for preview. Standard is the native Property selling Rate baseline, multiplier 1.00. Any explicit selected Unit Rate retains its existing priority and an upgraded unit may be chosen later.

This is a company-wide **quote/Operations policy**, not a waiver of real supplier inventory, native Unit booking checks, cost receipts or payment authorization. Hotels can replace an unavailable Hotel stage within the same destination and package level. A Nile Cruise that is unavailable can first be replaced by another suitable Cruise; converting its 3 nights to land hotels requires a revised itinerary, inclusions, services, and package price. Never show a hotel as an equivalent included 3-night cruise.

### Global policy
Source Tours.dev and Wayfatours store the mode as:

- Option name: etg-dynamic-pricing-controls_property_standard_rate_mode
- Option value: property_first_standard
- Controls panel: /wp-admin/admin.php?page=etg-dynamic-pricing-controls
- Standard base weight: 1.00, no extra margin/markup multiplication on an already selling Rate
- New read-only provider: wp-content/mu-plugins/014-mad4b-property-standard-base-v1.php

The provider delegates cruise source selection to MAD4B_Cruise_Price_Source_Fallback_V1 and Hotel source selection to MAD4B_Stay_Layered_Pricing_V1. A published Property Rate is accepted as a Standard selling reference only when the original engine verifies applicable dates, price, currency, pricing unit, board, occupancy and no stop-sale, and the Property Rate is not tagged supplier cost, QA-only or unapproved. Original Unit Rate overrides remain available when a unit is explicitly selected.

An absent positive amount means Standard Rate Required. The mode does not create or approve any Rate, Supplier Cost, WooCommerce Order, payment or booking. A Rate basis of per_person is not secretly converted to per_room_night and no quantity or night's count is manufactured.

## Tour 60623 — Name, ID, Type
60623 — Egypt Budget Tour: Cairo to Luxor Nile Cruise Discovery – 8 Days; tours-and-activities. Its canonical weekly departures are Monday and Wednesday. Original Tour direct selling amount remains USD 1290 per person. Wayfatours Form 6 2 adults on 2027-01-11/13 remains USD 2580 in nontransactional preview; Friday Jan 15 remains blocked.

The revised read-only Operations Board shows Cruise **Property choices**, Standard Rate status and Hotel fallback. No cabin column:
- Monday departure, Silver: 78032 — M/S Blue Shadow I (Property; Draft; no eligible Standard retail Rate).
- Monday departure, Gold: 35927 — MS Concerto II Nile Cruise (Property; published; Standard retail Rate missing).
- Wednesday departure, Silver: JAZ Gloria — external reference, no WordPress Property ID.
- Wednesday departure, Gold: 35972 — M/S Sonesta St. George Nile Cruise (Property; published; Standard retail Rate missing).
The previously recorded cabin research is retained but not required for preview.

The preexisting Hotel stages hotel-cairo-01 and hotel-cairo-05 retain their native, verified Property alternatives by package level. Examples:
- Silver: 36197 — Tolip Gardens Hotel; 36209 — Triumph Plaza Hotel.
- Gold: 36140 — Triumph Luxury Hotel; 36479 — Hilton Grand Nile Tower.
The Board draws all verified eligible Property candidates from the original itinerary source plan, not a newly invented hardcoded Hotel tier list.

If Aswan–Luxor cruise cannot be secured, the Board also offers Hotels ONLY as a revised-program alternative:
- Aswan 71495 — Tolip Hotel Aswan on Tours.dev; 71980 — Tolip Hotel Aswan on Wayfatours. Site-local IDs differ.
- Luxor 36142 — Iberotel Luxor and 36293 — Hilton Luxor.
These are published Hotel Properties in the verified city taxonomies. Tier/meal plan, number of Hotel nights, tours and transport require a separately approved replacement itinerary. No automatic switch or rate substitution occurs.

## Price and safety audit
Across each company, the current native Property Rate registry includes 56 published source sheet reference-COST rates generally STOP SALE=1, with supplier approval missing. Example Wayfatours 71931 — Supplier quote / Tolip Gardens Hotel / Summer 2026 / Double / BB / REVIEW ONLY ($43 per person) is supplier cost only and was NOT changed to a retail Standard Rate.

The tested Cruise Properties 78032 / 35927 / 35972 have zero published valid selling Standard Property Rates. Blue Shadow I is Draft. These items therefore display Standard Rate Required or Property Not Published, not a fabricated selling amount.

No native Pricing Rate or Woo Order was created and no supplier status was changed.

## Live testing and routing
- New MU Plugin deployed on Tours.dev and Wayfatours, plus updated wp-content/mu-plugins/mad4b-tour60623-operations-board-v1.php to display Property instead of Cabin and to show Hotel choices.
- WordPress global DPC option was explicitly persisted and read back on both sites.
- Source and Wayfa live acceptance passed with English and Spanish board rendering, Property fallback by city, identity/name links, Standard base weight 1.00, existing Native Rate counts preserved (4 legacy Rates on source, 0 on Wayfa), and unchanged Wayfa Form 6 price and date restrictions.
- An in-memory native source arbitration test verifies an eligible selling Property Rate can be the base when no Unit exists, an explicit Unit Rate stays higher priority, and a supplier cost source cannot be used as retail. No synthetic rate was stored.
- Tour edit link: /wp-admin/post.php?post=60623&action=edit (both hosts).
- Property edit pages show a read-only Standard base status for the current date.

## Remaining operational work
Approve real retail Standard Property Rate amounts, validity, basis and meal plan for chosen ships/hotels if missing; contract/net supplier cost for commercial margin checks; operational guest hotel or cruise and train availability for booking confirmation. Do not auto-convert supplier cost into retail or reuse the whole-package $1290 as an accommodation-only rate.

Git branch: feature/itinerary-overnight-native-stages-20261122, PR #1046, still subject to CI/release approval. VPS evidence and rollback preimages are in /opt/mad4b-package-checkpoints/2026-11-23-tour60623-property-standard-v1/.
Rollback only the new provider, exact preimage of the Operations Board, and global option if no intervening edits. Do not reset unrelated Booking, CSS, WPML or Rate engine components. Staging ETG/ARE not changed.

