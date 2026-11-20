# Sonesta Sun Goddess and Nile Goddess — Production enrichment completed (20 November 2026)

**CURRENT STATE: APPLIED AND INDEPENDENTLY VERIFIED ON WAYFATOURS PRODUCTION.**

This report replaces the earlier "not yet applied" status at this historical document path. That earlier run was stopped **before** VPS execution. There was no specific rejection reason exposed in VPS logs. On a subsequent user-authorized attempt, the *same* registered VPS root SSH action and the *same* `shell_access` key succeeded: first the read-only backup, then a fresh `plan`, one `apply`, a source `verify`, and independent readback. There was **no security-policy, SSH-key, root role or tool privilege modification**. Do not attribute the earlier stop to a specific rule or assert that a safety gate was disabled.

## Exact existing vessel identities and operator provenance

| Site Property | Source vessel | Original owner evidence | Operator-reported accommodation types |
| --- | --- | --- | --- |
| 35952 — Sun Goddess | Sonesta Sun Goddess Nile Cruise | https://www.sonesta.com/sonesta-sun-goddess-nile-cruise-ship/guest-cabins-and-suites | 26 Presidential Suites (40 m²), 2 Family Suites (60 m²), 1 Executive Suite (60 m²), 1 Royal Suite (60 m²); 30 source-described suites |
| 35906 — M/S Nile Goddess | Sonesta Nile Goddess Cruise | https://www.sonesta.com/sonesta-nile-goddess-cruise-ship/guest-cabins-and-suites | 47 Deluxe Cabins, 2 Senator Suites, 4 Presidential Suites; 53 source-described accommodations |

Sonesta direct unattended crawling was disallowed for the reviewed pages; evidence came from indexed operator-owned pages. The fleet overview's abbreviated Nile Goddess cabin total conflicts with its detailed accommodation breakdown. The detailed vessel-specific categories are retained *as source descriptions only*, not as supplier-approved available stock. Both operators' 3-, 4-, and 7-night recurring program descriptions lack date-specific inventory confirmation. Cruise operator sign-off for the Package Tours' actual day-by-day excursions remains outstanding.

## Live source writes

The existing WordPress `properties` records retain their published state, title and modified date. Each now has a private `_mad4b_cruise_public_web_amenities_evidence_v1` receipt. They received **12** official-source-based terms each, **24 assignments** in total, reusing the 204 existing bilingual `amenities` taxonomy terms without creating any new terms.

Seven native `units` CPT posts were created **as Draft** and each was related to its correct published ship via native JetEngine relation `223`:

| Ship Property | New Draft Unit | Cabin category |
| --- | --- | --- |
| 35952 | 71771 | Presidential Suite |
| 35952 | 71772 | Family Suite |
| 35952 | 71773 | Executive Suite |
| 35952 | 71774 | Royal Suite |
| 35906 | 71775 | Deluxe Cabin |
| 35906 | 71776 | Senator Suite |
| 35906 | 71777 | Presidential Suite |

All seven: `post_status=draft`, `unit_readiness=pending_review`, `unit_available=false`, correctly registered EN WPML post identity, source evidence present. They have **no** `unit_max_occupancy`, `unit_inventory_quantity`, or `unit_start_price` fields, no actual supplier allotment, no selected sailing date or commercial authorization.

## Operational proof and backup

- Prewrite private backup: `/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/cruise-web-batch3-before.json`, created with read-only WP-CLI before any Production write; independent schema/cardinality/permissions checks **passed**.
- Script: `scripts/mad4b-cruise-web-sun-nile-goddess-cli.php`; `MAD4B_CRUISE_WEB_BATCH3_MODE=plan|apply|verify`.
- PHP lint and Production `plan`: passed, 2 ship identities, seven categories, 24 reuse-only amenity assignments.
- Production `apply`: **exit code 0**, created two ship source receipts, seven Draft Units, seven JetEngine relations and 24 amenity assignments.
- Production `verify`: **0 errors**, correct Unit IDs 71771–71777, zero published or commercially ready cabins.
- Independent WordPress after-read: `/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/cruise-web-batch3-independent-after.json`.
- **172/172 prior Unit metadata/post fingerprints unchanged**, seven new Draft Units added (179 current).
- **171/171 old JetEngine relation `223` rows unchanged**, seven new native links added (178 current).
- **204/204 old Amenity terms and WPML crosswalks unchanged**; only the 24 ship-term relations were added.
- **32/32 old hand-authored Draft Tour Rates unchanged** in complete postmeta/status/modified SHA comparisons.
- Published Tour Rates / Property Rates / Transport Rates **all zero**; `catalog_activated=false`; company-definition and policy registries remain absent.
- CSS certification generated its own derived `_mad4b_ccss_route_generation_v1` data **1435 / 1436** on the ship posts; no pricing or inventory changes.

Do **not** rerun `apply`: the script is intentionally non-idempotent for creating new Draft Units and now must be used in `verify` mode for read-only reconciliation. The first attempt's blocking reason was not captured, so any recurrence must be separately diagnosed using the precise tool error; do not change privilege or bypass controls.

**Commercial boundary unchanged:** these are verified public-source vessel categories and amenities, not guaranteed supplier inventory, certified occupancy, approved sailing dates, operator-approved custom sightseeing sequences, price offers, or payment capability. PR #952 remains Draft and is not a release approval.
