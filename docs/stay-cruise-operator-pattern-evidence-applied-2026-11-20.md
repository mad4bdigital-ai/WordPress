# Wayfatours Cruise Operator Patterns — source evidence applied 20 November 2026

**CURRENT STATE:** 14/14 private WordPress review receipts applied to published EN/ES Package Tours and independently verified. These receipts are **not** operator approval, supplier booking, sailing or commercial Rate authorization.

## Why an operator-based matching step is required

Existing site-local Program term 2348 is the shared 3-night Aswan→Luxor reference. Five English Package Tours flagged mismatches under a generic assumed East/West Bank day sequence; two 4-night tours matched a generic reference yet still needed operator confirmation. A generic program **must not override the selected vessel's itinerary**.

Official Sonesta operator-owned *staging* example:
- Sun Goddess 3-night Friday Aswan→Luxor: Sunday West Bank 07:00 then East Bank 15:00 (same day), departure Monday. https://staging.sonesta.com/sonesta-sun-goddess-nile-cruise-ship/itinerary
- Sonesta St. George I 3-night Friday Aswan→Luxor: Sunday West Bank, East Bank Monday at 08:30 after disembarkation. https://staging.sonesta.com/sonesta-st-george-1-nile-cruise-ship/itinerary
- Sun Goddess 4-night Monday Luxor→Aswan: East Bank on Monday, West Bank on Tuesday. Same Sun Goddess source.

**Limit:** A staging-domain operator page is informative historical/reference material, not a signed current operator quotation. No vessel was selected in the seven site-local original cruise audit receipts, so none of these patterns is approved for a real departure date without exact supplier confirmation.

## Live changes and verified safety

Created only `_mad4b_cruise_operator_pattern_reference_v1` once for each of 7 English Tours and 7 Spanish Tours:
57562/57563, 60437/60611, 60439/60609, 60441/60607, 60443/60601, 60623/60661, 60629/60655.

Each stores existing immutable audit hash, exact Program term, operator-pattern examples, a review recommendation and explicit negative commercial assertions:
`selected_vessel_property_id=null`, `selected_vessel_supplier_verified=false`, `travel_date_specific_sailing_verified=false`,
`supplier_written_excursion_approval=false`, `rates_generated=false`, `checkout_ready=false`.

**Independent before/after from native WordPress**, not derived from the apply script:
- 14/14 original Tour titles, statuses, modified timestamps and `itinerary_item` SHAs unchanged.
- 14/14 existing cruise audits, Accommodation Stage plans and Unit bindings SHA-identical.
- 32/32 manually authored draft Tour Rates unchanged.
- Published Tour/Property/Transport Rates still 0. Catalog still disabled.
- 14 new source-only references created, verified by second `verify` and independent post-read.

## VPS immutable run receipts

`/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/`

- `cruise-operator-pattern-reference-cli.php` original exact code SHA256 `44c73309a2343627701d6e393a33a565bb2ea7f67c6e5ef5c2b88880762e0ece`.
- `cruise-operator-pattern-before.json` SHA256 `42befb60942ff69b76fae8a26f42979b1274f89ea8c415e6e112910e3383b99a`.
- `cruise-operator-pattern-after-independent.json` SHA256 `988f737400ff7c70671292f54c5c2266998463074a343bab30823ea7b3ea378f`.
- `cruise-operator-pattern-plan.json`, `cruise-operator-pattern-apply.json`, `cruise-operator-pattern-verify.json`.

**Next true supplier action:** select an exact, confirmed vessel and sailing date per Package, compare the operator's signed day-by-day sightseeing and embarkation/disembarkation sequence, and approve any changes to the actual Tour/Program only after content and WPML reconciliation. Confirm cabin class, occupancy, child restrictions, supplier cost, full board and allotment. No commercial readiness is conferred by this metadata.
