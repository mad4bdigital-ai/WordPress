# Sonesta Sun Goddess and Nile Goddess — source-only enrichment prepared

**Date:** 20 November 2026. **Status:** PHP code staged and `plan` verified, **not applied to Production**. An attempt to execute the multi-step Production backup/apply was blocked by a safety check before its execution. No Production changes occurred for these two ships. Do not treat the seven drafted category definitions as created Units.

## Vessel crosswalk to live Wayfatours

- **Sonesta Sun Goddess**, official https://www.sonesta.com/nile-river-cruises/sonesta-sun-goddess-cruise-ship , exact EN published `properties` ID **35952** with `post_title=Sun Goddess`; no existing linked Units or amenities. Official suite detail https://www.sonesta.com/sonesta-sun-goddess-nile-cruise-ship/guest-cabins-and-suites identifies 26 Presidential Suites (40 m²), 2 Family Suites (60 m²), 1 Executive Suite (60 m²), 1 Royal Suite (60 m²), exactly 30 suites. The areas are not maximum occupancy.
- **Sonesta Nile Goddess**, official https://www.sonesta.com/nile-river-cruises/sonesta-nile-goddess-cruise-ship , exact EN published `properties` ID **35906**, `post_title=M/S Nile Goddess`; no existing linked Units or amenities. Exact operator suite detail https://www.sonesta.com/sonesta-nile-goddess-cruise-ship/guest-cabins-and-suites lists 47 Deluxe Cabins, 2 Senator Suites and 4 Presidential Suites (53 in total). The Sonesta fleet-level listing ambiguously reports **47 Cabins & 2 Suites**, omitting the four Presidential Suites, so prefer the vessel-specific source pending operator confirmation.

## Proposed non-commercial staged changes (not executed)

- 2 private source evidence receipts under `_mad4b_cruise_public_web_amenities_evidence_v1`.
- 7 English-only Draft `units` class definitions (4 Sun, 3 Nile) with native `Property→Unit` JetEngine relation 223.
- 24 total existing Cruise Amenity taxonomy assignments (12 each) under existing `amenities`, with unchanged EN/ES WPML term relationships; **no new taxonomies**.
- Each proposed Unit: `unit_readiness=pending_review`, `unit_available=false`, no `unit_max_occupancy`, `unit_inventory_quantity`, `unit_start_price`, supplier allotment, departure, signed tariff or checkout authorization.

## Amenity provenance

Official operator: Sun Goddess private balconies, sun deck, restaurant, lounge, Wi-Fi, individual A/C, in-suite safe, spa/gym, outdoor pool and hot tub, minibar and massage. Nile Goddess restaurant, lounge, sun deck, panoramic windows, connecting cabins, spa/gym, pool, sauna, in-room dining, elevator, minibar, safe and A/C. Both publish 3-night Aswan→Luxor, 4-night Luxor→Aswan and 7-night round-trip programs. Recurring Friday/Monday schedule labels are **not** proof of a specific available sailing. Nile Goddess official page has conflicting free-vs-paid Wi-Fi wording; the Amenity existence can be recorded but fee status must remain unverified.

Sonesta direct automated retrieval disallowed by robots.txt; use indexed operator content for sourcing. No third-party images were downloaded or licensed for re-use.

## Execution status and safety receipts

- Bounded PHP source: `scripts/mad4b-cruise-web-sun-nile-goddess-cli.php`; `MAD4B_CRUISE_WEB_BATCH3_MODE=plan|apply|verify`.
- `php -l` passed; live `plan` passed with ship IDs 35952/35906, exactly 7 candidate classes, 24 existing Amenity assignments, 0 new terms, 0 commercial authorizations.
- **No apply executed:** Safety check blocked the requested Production backup/apply command before shell execution. After this, independent read-only WordPress query confirmed exactly 172 existing `units`, **zero** `mad4b-sonesta-v3-` Units, no source evidence or amenities/relations for either ship, and catalog disabled.
- Do not rerun `apply` until authorized Production execution is available again, the current site baseline is rechecked, and a full prewrite backup is successfully captured. In particular, rerunning the script under a different privilege/tool solely to bypass a safety denial is prohibited.
- Earlier processed fleet still contains 8 enriched ship records with 20 Draft source-category Units (other ships); these are not affected. Existing 32 manually authored Draft Tour Rates, CompanyCommercePolicy and commercial readiness remain unchanged.

## External approvals still needed

Actual sailing departures and operator sign-off for the seven Package itineraries, including five East/West bank sightseeing-day mismatches; cabin occupancy and child-policy by class, contracted tariffs, guaranteed allotment, photo rights, and company-bound CommercePolicy. Sources provide descriptions only.
