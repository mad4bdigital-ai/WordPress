# Second ship-specific operator-source Cruise Amenities batch — 20 November 2026

**Wayfatours Production, editorial-only.** Five currently Published Cruise Properties had existing source identities but zero Units linked to JetEngine relation 223 and zero Cruise Amenities assigned. A source-backed, exact-scope five-ship batch was executed after a saved independent pre-change snapshot. Only source evidence, operator-backed Amenities and new Draft Unit class references were written.

## Exact site identity / operator sources

| Existing EN Property | Official operator page | Source cabins/suites | Assigned EN Cruise Amenities |
| --- | --- | --- | ---: |
| 35957 Sonesta Dahabiya Amirat (**the original vessel, not Amirat II**) | https://www.sonesta.com/nile-river-cruises/sonesta-dahabeya-amirat | 5 Deluxe Cabins; 2 Elegant Suites | 8 |
| 35969 Sonesta Star Goddess | https://www.sonesta.com/nile-river-cruises/sonesta-star-goddess-nile-cruise-ship and official suite details | 27 Beethoven, 4 Mozart, 2 Chopin Suites | 9 |
| 35971 Sonesta Moon Goddess | https://www.sonesta.com/nile-river-cruises/sonesta-moon-goddess-nile-cruise-ship | 49 Deluxe Cabins; 4 Presidential Suites | 12 |
| 35972 Sonesta St. George **1 Nile Cruise** (not the hotel in Luxor) | https://www.sonesta.com/nile-river-cruises/sonesta-st-george-1-nile-cruise-ship | 47 Deluxe Cabins, 6 Presidential, 3 Elite, 1 Royal Suite | 12 |
| 35927 M/S Concerto **II** (not Concerto I, III, or Plus) | https://extensiongroup.com.eg/concerto-ii | 61 Doubles, 2 Singles, 2 Presidential, 2 Junior Suites | 9 |

The five official source pages were discovered using web search. Sonesta pages disallow direct unattended crawler access via robots.txt; facts were drawn from indexed operator content without bypassing this restriction. Extension Group's Concerto II page permitted an authenticated-time check of its live HTML and identified itself as Concerto II. No external images were copied. Officially published category totals are vessel descriptions, **not confirmed current inventory, departure capacity or contractual allotments**.

## Write coverage

Five private, source-linked _mad4b_cruise_public_web_amenities_evidence_v1 receipts on the existing five Published Cruise Properties. Operator names, official URLs, robots classification, cabin categories/counts, source warnings, non-commercial status and reviewed date were saved per Property.

Existing 14 English Cruise Amenities and their 14 Spanish WPML translations, with bilingual parent taxonomy groups, were **reused** and checked using raw term_taxonomy IDs to prevent WPML from silently collapsing an ES slug read to EN. The five vessels received **50 exact native amenity assignments** in total: 8/9/12/12/9 respectively. No taxonomies, new terms, or ship translation posts were created.

Fifteen **new English Draft Unit category records** attached through native JetEngine Property→Unit relation 223:

- Sonesta Amirat: Deluxe Cabin 71754; Elegant Suite 71755.
- Star Goddess: Beethoven Suite 71756; Mozart Suite 71757; Chopin Suite 71758.
- Moon Goddess: Deluxe Cabin 71759; Presidential Suite 71760.
- St. George I: Deluxe Cabin 71761; Presidential Suite 71762; Elite Suite 71763; Royal Suite 71764.
- Concerto II: Double Cabin 71765; Single Cabin 71766; Presidential Suite 71767; Junior Suite 71768.

For all 15, post_status=draft, unit_readiness=pending_review, unit_available=false. Source cabin counts saved only as informational evidence. **No unit_max_occupancy, unit_inventory_quantity, unit_start_price, departure timetable, actual voyage supply, supplier tariff or checkout readiness** added. No Spanish Draft Unit posts created. No paid or Published Rates.

## Independent verification, preservation and recovery

- Backup: /opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/cruise-web-batch2-before.json
- Readback: /opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/cruise-web-batch2-independent-after.json
- Guarded source: scripts/mad4b-cruise-web-operator-amenities-batch2-cli.php. plan/apply/verify all completed successfully, verify error_count=0. **Do not repeat apply.**
- Original **157** Units and postmeta hashes unchanged. Five existing ship titles, status, modified date and all old metadata unchanged except one new source receipt and CSS's own derived generation record per ship.
- Original **156** JetEngine links unchanged, +15 new. Original **204** Amenities and WPML translations unchanged, +0 new.
- Original **32** handwritten Draft Tour Rates unchanged in full metadata/status hashes. Published Tour/Property/Transport Rates all 0; catalog_activated=false.
- WordPress performance hook generated derived _mad4b_ccss_route_generation_v1 values 1430–1434 after content changed. Preserve this evidence in CSS certification lifecycle; not related to Rate pricing.

## Separate identity and source discrepancies to reconcile

- Existing published Nile Dolphin entries 35806 and 36010 appear to name the same vessel with different town taxonomy, but were **not merged or reclassified** based on name alone. Reconcile exact operator identity and WPML before assigning units/amenities.
- Existing Property 35814 named Nour El Nil represents an **operator fleet**, not a particular ship. Its individual currently listed boats require a crosswalk; no imaginary shared fleet cabin inventory should be assigned.
- Sonesta Amirat II 35817 and original Amirat 35957 are two different vessels. Some Sonesta fleet landing-page count snippets differ from its per-vessel detailed cabin page; use vessel-specific operator content as source of truth pending supplier confirmation.
- Any of these ship records may require an exact current departure / ship operator itinerary check before native Rate composition.

All commercial product creation, checkout and supplier pricing gates remain independent. A normal customer-facing Properties catalog was not activated. PR #952 remains Draft.
