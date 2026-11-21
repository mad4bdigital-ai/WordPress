# Native Package sourcing — original destination, hotel Units, cruise, sleeper-train evidence

**Date:** 2026-11-21 (Africa/Cairo). **Work:** Draft PR #987; selectively applied live to Tours.dev and Wayfatours. **Authority:** WordPress native `properties`, `units`, `transportations`, `stations`, `programs`, source Package stage plans; no copied prices and no generated Tour Rates.

## What was verified

| Original Package source stage | Published native Property discovery | Native tier assignment | Relation 223 Room Units | Published Property Rates | Status |
|---|---:|---|---:|---:|---|
| 60437 / hotel-aswan-02 | **5** governorate-level hotels (after **3 Abu Simbel exclusions**) | None of 5 mapped Silver/Gold/Diamond | 0 | 0 | Candidate discovery only; **all 5 lack verified Aswan-city term** |
| 60627 / hotel-luxor-02 | **11** Luxor hotels | None mapped to Package Level | 0 | 0 | Candidate discovery only |
| 60629 / resort-sharm-03 | **43** Sharm hotel/resort Properties | None mapped to Package Level | 0 | 0 | Candidate discovery only; original Package board policy is AI |

**Crucial:** Property's native `stars` term does not automatically define Package Level. The existing Silver Cairo candidate pool already contains 4-star and 5-star hotels. No 3-star→Silver / 4-star→Gold / 5-star→Diamond policy exists as an approved mapping. Tier terms for the 59 destination Properties were **not fabricated** and no fake Unit/Cost data was entered.

The new native commercial generator guard and read-only Pricing Lab both reject Abu Simbel as Aswan-city overnight. A hotel only tagged `aswan-gov` cannot become eligible until independently verified and tagged `aswan-city`. Out-of-destination Property IDs return `property_destination_mismatch` before the more specific location proof status `aswan_city_location_unverified`.

## Source-backed sleeper train discovery

Tour `60623` original itinerary and Package stage geometry provide two distinct overnight train legs:

| Tour stage | Original program evidence | Native train CPT candidate | Route source check | Commercial state |
|---|---|---|---|---|
| sleeper_train-train-02, Day 2 | Previous validated Cairo hotel stage ends on Day 2; original day says sleeper to Aswan | `transportations` **54552**, Sleeping Train **No. 86**, Cairo→Aswan | Published native `stations` from Ramses through Aswan in increasing stop sequence | **No published transport-rates, no cabin tariff/supplier proof** |
| sleeper_train-train-04, Day 6 | Original itinerary says Luxor→Cairo sleeper | `transportations` **54553**, Sleeping Train **No. 87** (Aswan→Cairo via Luxor) | Luxor stop precedes Ramses stop on native published route | **No published transport-rates, no cabin tariff/supplier proof** |

The native `trans_train_stops` `sort_num` is used only as direction evidence, **not** a certified timetable, true ticket inventory or supplier booking confirmation. QA Draft train fixtures excluded.

Cruise stages use their saved original `cruise_program` with program ID, direction, program day count and saved original source. For example Tour 57562 has program `2348` Aswan→Luxor, 4 program days/3 nights. No vessel/cabin/sailing/quote has been invented, and the commercial Rate generator retains its dedicated cruise-Rate-stage integration blocker.

## Actual site changes

- Added `wp-content/mu-plugins/mad4b-package-stage-sourcing-v1.php`: native source-only Property discovery and unique route-matched sleeper train discovery; no new CPT, site option, Price, Unit or postmeta.
- Integrated `source_discovery` readback into existing `mad4b-package-pricing-lab-readiness-v1.php`, only when a stage has no eligible hotel candidates or is a cruise/train specialist.
- Added collapsible source tables into the existing *four-step progressive Pricing Lab* (under **Assignments**), preserving live tooltips, guide/transport roles, step navigation and original editor links. Visible Property source rows are NOT automatically eligible package candidates or bookable price sources.
- Strengthened native `MAD4B_Package_Stage_Rate_Guard_V1` and Pricing Lab per-stage eligibility to block Abu Simbel and require Aswan-city exact proof, without changing original Package Rate fingerprints or prices.
- All files selectively deployed to both site runtime MU-plugin locations with root-only prechange copies under `/opt/mad4b-company-provisioning/reports/package-stage-sourcing-live-20261121/`. The live enhanced four-step UI PHP/JS/CSS was reconciled back to Git **without overwriting it with older simplified PR copies**.

## Acceptance

Wayfatours:
- Native stage-source routing readback **445/445** PASS; 9 per-tier missing-hotel source discovery views (Aswan/Luxor/Sharm × 3); sleeping train IDs 54552 and 54553; cruise 2348; zero invented Rates.
- Existing Pricing Lab **103/103** PASS; stage destination **2,860/2,860** PASS.
- Native Package Rate generator **212/212** PASS. WPML, manual/auto generator refusal, non-Package rate planning unaffected.
- Authorized WordPress Master Admin nonce-protected AJAX readback confirms the correct source hotel / train / cruise information for **60437, 60627, 60629, 60623**, supplier total NULL and native generator ready=false.
- Rates did not change in smoke tests.

Tours.dev:
- The native saved Package source-plan count **changed during this operation from zero to five, then to eight** through independent content reconciliation. We did **not** author or claim those source-plan content writes. Current source has 8 canonical Package plans with 84 stage/tier inspections.
- Current Pricing Lab **99/99** PASS; destination stage **13/13** PASS; source discovery **15/15** PASS; native Rate generator **40/40** PASS, all with zero Rate mutations and no false commercial approval.
- Saved candidate parity still differs: source plan readback had **5 unique Properties and no linked source Units** vs Wayfatours **18 unique Properties and 134 source Units**. The source plan candidate IDs must NOT be copied directly across site databases.

## Cross-site identity hazard (required before further source parity writes)

Exact native post-identity audit on the curated Wayfatours **18 Cairo tier candidate Properties**:

- **10/18** have a unique title/slug identity match to source native `properties` records; those may be reconciled only after verifying the original source/translation IDs and tier taxonomy.
- **8/18** Wayfatours curated candidate Properties have **no exact native Property identity match** on Tours.dev: target IDs `70732, 70733, 70734, 70735, 70736, 70737, 70738, 70739`.
- Worse, the same integers `70732`–`70739` are existing **`transportations` posts** on Tours.dev. For example Wayfatours `70734` is a Cairo Four Seasons hotel, while Tours.dev `70734` is a Luxor tour-component transportation. **Never copy post IDs across site DBs without typed site-scoped identity mapping.**
- The eight absent native Property records were NOT auto-created, published, or conflated with superficially similar source hotels. A controlled, identity- and image-rights-verified Property import/translation workflow is needed.

No imported Properties, fake Units, price receipts or new native Rate generation were performed in this source-discovery work.

## Remaining to reach commercial Rates

1. Resolve verified identity of source-vs-target Properties; selectively stage/provision genuinely missing Properties with site-scoped IDs. Never treat matching numeric IDs as matching entities.
2. Choose Package tier-specific **actual hotels** for Aswan, Luxor and Sharm, based on vetted product offer/grade and a verified city location. Assign native `package_levels`, or the exact original stage candidates, after evidence.
3. Create real Unit types and Relation 223 from supplier room/cabin facts (not guessed capacity), and obtain date-specific self-supplier/contractual cost, currency, board, occupancy and published non-QA Property Rate receipt.
4. Establish a route/date/cabin-specific supplier Price for sleeper train 86/87; validate schedule and availability instead of relying on native route stop ordering.
5. Link cruise native vessel, program, direction, sailing, cabin Unit and supplier tariff, then connect cruise and train typed components to the native Tour Rate stage generation writer.
6. Complete nights, guide/transport, children, FX, margin/tax and approval; run positive Draft Rate generation and end-to-end quote/deposit/checkout tests only after real source providers are qualified.

**Boundaries:** All original missing costs remain **NULL**, not zero. `checkout_ready=false`, `generated_rate_ready=false`, no catalog activation. A successful WP-CLI or AJAX readback is **not** a logged-in visual browser interaction test.
