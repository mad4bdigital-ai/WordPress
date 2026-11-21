---
name: nile-cruise-rate-readiness
description: "Use when auditing an Egypt Nile Cruise Tour Rate for Ready to Publish, supplier-confirmed sailing direction, seasonal validity, occupancy, minor-age pricing, and booking readiness."
---

# MAD4B Nile Cruise Rate publication integrity

Use the existing MAD4B WordPress/Commerce policy engines and the Tour/Rate/Property relation; do not invent new global pricing controls or synthetic operator approvals.

1. Discover the Tour's `related_program_terms`, the Rate's `related_program_term_id`, the actual supplier vessel from `tour_cruise_property_id`, and Rate `related_properties_id`/stay stages. Use each site's own taxonomy IDs and WPML mappings; prefer stable term slugs/relations.
2. Verify `tour_rate_start_date` and `tour_rate_end_date` against the resolved `season` taxonomy metadata; a populated date alone is not evidence of a valid season.
3. For combined 7-night Nile programs, require an explicit `mad4b_cruise_route_variant_v1` and `mad4b_cruise_supplier_confirmed_v1=1`; the valid variants are exactly those exposed by `MAD4B_Cruise_Program_Composer_V1`. A boolean confirmation is editor attestation; source evidence still needs review.
4. For a single 3- or 4-night Program, verify itinerary direction against the canonical Program; do not require a 7-night variant. Never treat a static `cruise_runs` label as verified sailing dates.
5. Check occupancy unit, adult prices, age bands, actual child and infant pricing rules, rates by market/currency, and booking-policy precedence using the real MAD4B resolver. Empty ages, fallback age bands, or adult-only prices do NOT authorize a family quote.
6. Treat Extras, optional transfers, and payment as independent dependencies. A Rate can be valid to display but still have `checkout_ready=false`; do not create payment links, orders, or approve deposits from a publication check.
7. Preserve Draft saves. Publishing a NEW Nile Cruise fails safely when required data is missing, with structured codes and links to offending Tour/Rate/Property records. Existing published Tours are not retroactively downgraded by discovery.
8. For any repair: verify company authority, capture exact preimage, make minimal site-local changes, record separate validation evidence and a rollback path. Never silently set supplier confirmed, season, child policy or rate price.

Return: `ready_to_publish`, `booking_ready`, `rate_program`, `route_variant`, `supplier_evidence`, `season`, `occupancy`, `minor_pricing`, `issues`, `recommended_repair`, `independent_readback`.
