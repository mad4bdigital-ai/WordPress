# Wayfatours eight Package Tour Plans — room Units and Nile cruise Program links

**Execution date: 20 November 2026.** This is a source-data alignment and program verification for eight EN Package Tours and their eight WPML ES siblings. It **does not** admit a sellable hotel/cabin Unit or create any Tour Rate.

## What was linked in Production

16 new private Tour records under `_mad4b_package_stay_verified_room_program_links_v1`, one per EN/ES post. They bind to exact SHA-256 hashes of each current `_mad4b_package_accommodation_source_plan_v1` and `_mad4b_package_stay_component_discovery_v1`.

- **32 Cairo hotel stage references across both languages:** exact JetEngine relation 223, approved 18-hotel editorial cohort, original tier alternatives 5 Silver / 6 Gold / 7 Diamond. A source room map lists genuine Published linked Unit IDs under their correct Property; *not a Woo booking selection*. Each room Unit is still `unit_readiness=pending_review` and `unit_available=false`.
- **134 English Units in editorial cohort:** native `unit_kind` distribution 82 `room`, 50 `suite`, 2 `other` (Pool Cabana #70796 and Chalets #70805; not silently presented as ordinary hotel rooms). 56 record a numeric maximum occupancy; remaining 78 require occupancy verification. Source verification and editorial publication receipts exist for every Unit, but supplier availability and commercial approval do not. No ES Unit translations were invented.
- **14 localized Nile Cruise stage references** (seven unique EN stages): verified by current source program taxonomy plus each tour's night count and city-direction evidence. 8 locale stages for 3 nights, 6 for 4 nights. **No vessel, sailing departure, or cabin Unit IDs assigned**.

## Native Nile cruise program evidence

| Canonical EN term | ES WPML term | Provider days | Nights | Route | Result |
| --- | ---: | ---: | ---: | --- | --- |
| 2348 | 2445 | 4 complete days | 3 | Aswan to Luxor | day_by_day verified, no warnings |
| 2349 | 2449 | 5 complete days | 4 | Luxor to Aswan | day_by_day verified, no warnings |
| 2350 | 2453 | composed 8-day preview | 7 | 4 available route variants | composition ready; variant and supplier confirmation required |

For term 2350, `MAD4B_Cruise_Program_Composer_V1` generated exactly eight preview days for all four variants `luxor_roundtrip`, `aswan_roundtrip`, `luxor_to_aswan_leisure`, `aswan_to_luxor_leisure`, but no provider or confirmed sailing was inferred. The current eight Package Tours have no seven-night stage; term 2350 was not incorrectly assigned.

**Itinerary caveats:** The tour itinerary and canonical Program have matching duration, start and end region, not guaranteed identical daily excursion order. The first and disembarkation-day itinerary mentions were checked. In 60629, the disembarkation day omits Aswan but the preceding onboard night explicitly names Aswan. This separate source-evidence variant remains flagged. Every commercial-sailing match requires supplier timetable, ship/cabin/room, meal board and departure availability.

## Preserved governance

- No new or modified `Units`, `properties`, cruise `programs` taxonomy metadata, legacy Tour Rates, Property Rates or Transport Rates.
- Native Unit/ship/cabin commercial eligibility remains **0**, missing supplier prices remain `pending_quote`, no `_booking_experience_stay_stages_v1` exported, `catalog_activated=false`, checkout disabled.
- Independent before/after fingerprint comparison: 16 existing Tour Source Plans, candidate receipts, Itinerary/Includes/Policies, all 32 old Draft Tour Rates, all 134 Published Unit postmeta records and six Program term-meta snapshots unchanged.
- Site CompanyCommercePolicy remains unconfigured, with no invented site-bound company definition or policy registry.

## Code and rollback

`scripts/mad4b-package-eight-room-and-cruise-program-bindings-cli.php` is fail-closed, supports `plan`, one-time `apply`, and `verify`. The new metadata is add-only and never a live Rate stage. **Do not repeat apply.**

Private VPS backup: `/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/package8-room-program-bindings-before.json`.
Postwrite independent readback: `.../package8-room-program-bindings-independent-after.json`.
The only new data are 16 bounded records under `_mad4b_package_stay_verified_room_program_links_v1`. To reverse, use a separately authorized exact-match delete of those 16 records, not a broad tour or WPML reset.

**Further action required** to make cabins commercially usable: select real vessel per sailing, obtain ship-specific cabin categories and capacities, supplier inventory and rates and valid departure calendar; create native verified Room/Cabin Units and JetEngine 223 relations only once those facts exist. Regional Aswan/Luxor hotel rooms and Sharm Resort units likewise have no verified published inventory. PR #952 remains Draft with governing release gates.
