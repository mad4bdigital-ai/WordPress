# Package Indicative Sales Draft: Tours 57562, 60623, 60629 — live delivery

Verified on Tours.dev and Wayfatours, 22 November 2026. Tours.dev live code is canonical; Git follows the live changes selectively.

## Editorial-only Sharm Resort inventory

The original Sharm Resorts Web Rates worksheet, gid 20261122, has seven public web prices, all Pending Reservations Cost Review and no approved supplier net cost.

Property IDs 36084, 36246, 36282, 36127, 36226, 36107 and 36186 received the additive Resort property-type term, retaining their existing Hotel term, and the guarded editorial public-web-offer reference metadata. The recorded per-night USD retail observations are 375, 160, 175, 195, 397, 478, 285, in worksheet row order (rows 4–10). Different dates, rooms, taxes, occupancies, and meal plans mean these values must not be compared as supplier net prices or automatically converted into full 3-night offers.

Only Steigenberger Alcazar (Property 36084) has an explicit operational Package Level assignment among these seven: Gold/Diamond. Therefore its Resort overnight kind is available for planning on source Tour 60629 Gold, and Wayfatours Gold/Diamond. The remaining six are visible as research-only, pending Operations tier assignment. Silver has no operations-qualified Resort yet. All cost, availability, and booking safeguards remain independent.

## Existing Pricing Lab integration

The new read-only MAD4B Package Indicative Offer provider enriches the current authenticated Pricing Lab report, rather than building a competing pricing engine. The Sales Draft lists each source stage, missing supplier inputs, web offer evidence, and nonbinding per-room 3-night mathematical illustrations for Sharm, preserving source URL, freshness and pending review state.

No complete Package price, Starting From, margin, sale Rate, payable deposit, or WooCommerce checkout authorization is inferred from incomplete data. Tours 57562 and 60623 have explicit incomplete hotel, cruise, and sleeper-train stages, while 60629 also has an incomplete resort stage. Abela train 86 and 87 fares remain public 2026 reference information, not approved cabin costs.

Changed native runtime paths:
- wp-content/mu-plugins/mad4b-package-indicative-offer-v1.php
- wp-content/mu-plugins/mad4b-package-pricing-lab-readiness-v1.php
- wp-content/mu-plugins/mad4b-smart-itinerary-choices-v1.php
- wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js
- wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.css
- wp-content/mu-plugins/assets/mad4b-smart-itinerary-overnight-v1.js

Migration script: tools/wp-cli/enroll-sharm-resort-editorial-web-offers-20261122.php (one-time, guarded). Do not re-run after changing property metadata without reconciliation.

## Audit and boundaries

- 4 server-side Tour/Tier previews passed on Tours.dev and 5 on Wayfatours; original pricing report and commercial restrictions preserved in isolated preflight.
- Live readback on both: 7 Sharm web references, 1 operations-tier-matched Resort, 6 requiring tier assignment; 60623 and 60629 still have checkout disabled.
- 5/5 new JS tests and 10/10 old Smart Itinerary JS regression tests passed.
- Six externally served updated CSS/JS assets returned HTTP 200 and exact source SHA.
- Customer-facing quote, browser-authenticated itinerary Save/Readback, inventory, dated net supplier prices, and commercial Rate authorization have NOT been certified or created.

Next: Operations assigns tiers for the six Resort properties where appropriate. Reservations reviews dated AI plans, room and child occupancy, taxes, cancellation and signed supplier net quote in the existing worksheet. The existing Property, Unit, Transportation, Package Rate Generator and WooCommerce checkout verification handle commercial approval after supplier receipts; no retail web offer is automatically promoted.

Live evidence, backups, and rollback preimages are held under /opt/mad4b-package-checkpoints/2026-11-22-package-indicative-quote-v1/.


## Post-release WPML source-locale correction (22 Nov 2026)

During live EN/ES QA, the new Sharm pool originally disappeared under ES on both companies because WPML scoped an original-English Property taxonomy query to the active editor language. Fixed the new provider with a canonical source-locale wrapper that always restores the original admin language in a finally block. This changes only read-time editorial offer discovery, not saved taxonomies, tickets, cost or checkout.

Verified live: Tours.dev EN, ES, FR, DE, AR and IT all resolve **7** public Sharm web offers and **1** operations-tier-matched planning-only Resort (Gold). Wayfatours EN and ES pass the same checks. No price total is produced, and checkout stays false. WPML restoration was checked for both EN and ES.

Native Pricing Lab output stays protected by the original Tour edit permission and authenticated nonce. The provider supplies no new public quote endpoint; its staff-only draft response is attached to the existing protected report.
