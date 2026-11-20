# Wayfatours Package 60441 — exact bilingual duplicate removal and stay-plan qualification

Execution date: 20 November 2026. Scope: published English Tour 60441 (Luxury Cairo and Nile Cruise — 10 Days) and WPML Spanish Tour 60607, existing same translation group 1218296.

## Root cause and bounded fix

Both 10-day package posts carried 11 JetEngine itinerary_item rows. The sixth row (item-5) was byte/field-identical to the fifth row (item-4), representing Day 5 Luxor West Bank. Each Includes repeater also contained a second exact copy of the 5 Cairo hotel nights at item-5. The fix removes only the second identical row from each list in each language, and reindexes the remaining JetEngine item-N keys sequentially. All surviving row contents and order are preserved. Original publish statuses and post_modified values remain untouched.

Existing itinerary_item: 11 -> 10 rows per language; Includes: 14 -> 13 per language. Each original field, raw serialized WordPress meta and the corresponding private Tour Source Plan were backed up before mutation. The exact metadata update was guarded by meta_id and old raw value and written with transaction COMMIT / ROLLBACK, not by bulk content import. The live audit metadata key is _mad4b_package_itinerary_duplicate_remediation_v1.

## Source Plans after fix

Source Plan key: _mad4b_package_accommodation_source_plan_v1. Only the two affected Source Plan records changed: source hashes for itinerary_item and includes, source_itinerary_record_count 11 -> 10, virtual duplicate flag true -> false, duplicate-only issue removed, source_review_status blocked_duplicate_itinerary -> draft_geometry_verified. The three stage groups remain Cairo hotel 3 nights, Nile Cruise 4 nights, Cairo hotel 2 nights. Total 9 nights, 10 days. No Rate stages or Tour Rates created.

The complete eight-package source plan verifier now explicitly requires a truly unique Day 5 for both languages, an Includes list with one five-night Cairo entry, and the immutable correction receipt. The 16/16 source plans and 56 localized stage rows passed the full native hotel-candidate verification; all 16 plans now have source_review_status draft_geometry_verified.

## Evidence and recovery

- Private full prewrite backup: /opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/package60441-dedup-prewrite-backup.json (raw metadata values and 32 old Draft Tour Rate SHA256 fingerprints).
- Bounded CLI: scripts/mad4b-package-60441-bilingual-exact-dedup-cli.php supports plan, one-time apply, verify. Do not rerun apply after success.
- Native source plan CLI: scripts/mad4b-package-eight-accommodation-source-plan-cli.php supports plan, one-time apply, verify; after the original installation use verify only.
- Live duplicate correction 2 itinerary rows removed across both languages, plus 2 duplicate Includes rows removed; source plan corrections 2/2; 0 errors.
- Independent comparison of all 32 existing hand-authored Draft Tour Rates: full postmeta, status and modified timestamps unchanged.
- No Published Tour Rates or Property Rates, no orders or checkout, catalog_activated=false, public Properties/Units paths remain HTTP 404.

## Remaining independent commercial gates

All 16 plans have now cleared geometry duplication, but are still source-only, with pricing status pending_quote and booking_authorized=false. CompanyCommercePolicy remains policy_company_binding_invalid (company definitions and policy registry absent); do not fabricate a registry. Cruise vessel/cabin, sleeper train, Aswan/Luxor hotel and Sharm resort suppliers and occupancy/rates remain unverified. Native Tour Rate Stage repeater cannot represent these service kinds without a new bounded adapter. PR #952 remains Draft; no merge/release authority granted.
