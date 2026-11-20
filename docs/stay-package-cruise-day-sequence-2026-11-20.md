# Wayfatours — Nile Cruise Program day-sequence verification, Package cohort

**20 November 2026, Production.** After linking the proper Nile Programs and hotel room Unit source records, a second and stricter verification compared **the actual day-by-day sightseeing order** in each Package Tour against the generic reusable Nile Program's *primary Luxor east/west-bank day order*. This does not assert supplier-confirmed vessel dates or complete excursion parity.

## Results (one unique cruise stage per EN Package)

| Tour EN / ES | Cruise term EN / ES | Duration | Main tour itinerary compared with canonical Program |
| --- | --- | --- | --- |
| 57562 / 57563 | 2348 / 2445 | 3 nights / 4 days Aswan→Luxor | Difference: Tour handles East and West Bank on disembarkation Day 7, not generic Program's separate Day 3 East + Day 4 West sequence |
| 60437 / 60611 | 2348 / 2445 | 3 nights / 4 days Aswan→Luxor | Difference: Tour West Bank Day 6, East Bank Day 7; reverse of generic Program |
| 60439 / 60609 | 2348 / 2445 | 3 nights / 4 days Aswan→Luxor | Difference: Tour West Bank Day 5, East Bank Day 6; reverse of generic Program |
| 60441 / 60607 | 2349 / 2449 | 4 nights / 5 days Luxor→Aswan | High-level match: East Bank Day 4, West Bank Day 5 |
| 60443 / 60601 | 2349 / 2449 | 4 nights / 5 days Luxor→Aswan | High-level match: East Bank Day 3, West Bank Day 4 |
| 60623 / 60661 | 2348 / 2445 | 3 nights / 4 days Aswan→Luxor | Difference: Tour handles West and East Bank on cruise-disembarkation Day 6, not generic Day 3 East + Day 4 West |
| 60629 / 60655 | 2349 / 2449 | 4 nights / 5 days Luxor→Aswan | Difference: Tour West Bank on embarkation Day 4 and East Bank Day 5, reverse of generic Program |

**5/7** source Package families differ from the generic program's primary sightseeing day order. **2/7** have compatible major bank-order features only; even these need vessel, departure, sailing and supplier contract confirmation. All seven are **not automatically exportable** into saleable Rate/Checkout.

The original Program 2348 Day 3 description includes optional contingency that East Bank visits *may be combined with the West Bank* in case of a delay at Esna Lock. That flexibility is not proof a specific Package's shifted programme or guide/transfer time is supplier-approved. The site's original Package itineraries and source Program term metadata were not edited.

## Provenance and safety

- 14 private WPML bilingual metadata receipts recorded under `_mad4b_cruise_program_day_sequence_source_audit_v1` for seven exact EN/ES published Tour pairs.
- The source review is deterministic and bounded: the Program's native days/title must be present and numbered, with no parser warnings; actual Tour itinerary days are checked for Luxor East vs West via explicit named places in EN and ES; EN and ES outcome must agree. Detailed source hashes bind current Tour itinerary, existing Source Plan, Unit/Program links and Program term metadata.
- Statuses: 10 localized receipts `primary_bank_sequence_differs_operator_review_required`, 4 `primary_bank_sequence_matches_requires_operator_confirmation`.
- Both statuses explicitly have `operator_supplier_excursion_sequence_confirmed=false`, `native_program_exact_tour_itinerary_parity_confirmed=false`, `auto_export_native_rate_stage_permitted=false`, `supplier_sailing_calendar_verified=false`, `commercial_cabin_units_verified=false`, and `checkout_ready=false`.
- Private prewrite backup: `/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/package-cruise-day-sequence-before.json`; independent postwrite audit `.../package-cruise-day-sequence-independent-after.json`.
- **Independent after/before fingerprints matched:** the complete original Tour itinerary/Policies/Source Plan/Unit links on all 14 Tour posts; 32 original hand-built Draft Tour Rates; 134 Published Units with all postmeta; and six EN/ES Cruise Program term meta records. Published Tour/Property/Transport Rates=0; catalog disabled; company definition/policy registry absent and untouched.
- Code supports `MAD4B_CRUISE_DAY_PARITY_MODE=plan|apply|verify`; the one-time Production apply has already occurred. Only verify may be rerun.

## Required next supplier evidence

For each of five divergent tours, the vessel/operator must explicitly agree to the proposed day-of-visit changes and associated private guide and airport/rail/road transfers, possibly using a Tour-specific sightseeing overlay without modifying canonical generic Program for every tour. Do **not** rewrite the public Tour itinerary or the generic Program silently. For all seven, select a real ship, actual confirmed departure, room/cabin type, capacity, child policy, availability and cost before admission to a priced Tour Rate. Draft Source Plans and the 14 review records are not commerce authorizations.
