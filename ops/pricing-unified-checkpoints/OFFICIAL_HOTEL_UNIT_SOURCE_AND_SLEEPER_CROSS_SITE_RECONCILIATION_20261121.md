# Official Hotel Unit source intake and Sleeper Train native station identity — live handoff

**Date:** Saturday, 21 November 2026 · **PR:** #987 Draft · **Scope:** Wayfatours Production and Tours.dev staging source.  
**Authority:** Live native WordPress posts, JetEngine CPT/Relation 223 and verified official hotel category pages; no synthesized procurement cost or new Tour Rate.

## 1. Commercial baseline and constraint

Canonical Package Tour IDs: `57562,60437,60439,60441,60443,60623,60627,60629`; tiers Silver `2353`, Gold `2354`, Diamond `2356`. Original stage source plans and binding SHA-256 match on both websites, but **zero stage selected Property** and **zero published Property Rates or Transport Rates** exist; all 24 Package-tier combinations remain blocked by the existing native `MAD4B_Package_Stage_Rate_Guard_V1` commercial guard.

Do not infer tier from stars. The original source Package Levels for the destination hotel pool are not set, even for properties with 4–5-star taxonomy. No raw numeric WP post ID mapping across sites.

## 2. Authoritative address and room/category sources written

| Original native Property | Verified evidence | Unit catalog, status | Wayfatours unit IDs | Tours.dev unit IDs |
|---|---|---|---|---|
| `36181` Obelisk Nile Hotel, Aswan | [Hotel rooms](https://obeliskhotels.com/rooms/), [hotel contact](https://obeliskhotels.com/contact/) | **4 draft** source categories, no room occupancy independently verified | `71874–71877` | `71394–71397` |
| `36293` Hilton Luxor Resort & Spa | [Hilton official property gallery](https://www.hilton.com/en-gb/hotels/luxhitw-hilton-luxor-resort-and-spa/gallery/) | **1 draft** Deluxe King Room (occupancy unknown) | `71879` | `71399` |
| `36084` Steigenberger Alcazar, Sharm El Sheikh | [H Rewards original hotel page](https://hrewards.com/en/steigenberger-alcazar-el-sheikh) | **12 draft** room/suite/villa category rows; **11** have officially supplied numeric capacity, Royal Suite remains unknown | `71880–71891` | `71400–71411` |

17 newly prepared `units` Draft posts **per site**. Each one is linked to its original `properties` parent via native `jet_engine()->relations->get_active_relations()[223]->update(property,unit)`, and has a typed original source receipt `_mad4b_room_source_receipt_v1` containing room category and official source URL. Unit kind `room`, `suite` or `other` where verified; `unit_readiness=pending_review`, `unit_available=false`, and supplier price, inventory quantity, positive period-based Property Rate, QA-public checkout and booking approval are all deliberately absent.

For `36181`: `property_map = "Corniche El Nile St, Aswan, Egypt"`, added original `aswan-city` destination alongside `aswan-gov` from hotel's official contact; private audit meta `_mad4b_property_location_evidence_v1` declares **street-address text only** with `latitude=null, longitude=null` (no fabricated coordinates). Other four Aswan governorate hotels still lack original city proof; three known Abu Simbel hotels are excluded from Aswan-city lodging. Hilton/Alcazar have neighborhood-grade official locality evidence saved but their original `property_map` remains unset rather than pretending a verified street/geopoint.

**Explicit business boundary:** these 17 draft room categories are **not** published room stock. Original `unit_max_occupancy` was filled only for the 11 Alcazar entries with supplier-published capacity. Obelisk/Hilton missing occupancy was never assumed. All Supplier amounts and real-time availability remain `NULL`; no rate setting or checkout modification was made. Actual existing Unit metadata is not overwritten when a previously source-receipted Unit exists.

Provisioning scripts used, preflight-authorized, deterministic source/slug/type checks:
- `ops/pricing-unified-checkpoints/scripts/provision-obelisk-official-units-20261121.php`
- `ops/pricing-unified-checkpoints/scripts/provision-hilton-alcazar-source-unit-drafts-20261121.php`

Run only explicitly via authorized WP CLI after preflight; **not cron/CI automation** and not a supplier cost generator. Original live pre-write snapshot and rollback receipts root-only:
- `/opt/mad4b-company-provisioning/reports/obelisk-aswan-native-source-20261121/{wayfatours,toursdev}-before.json`
- `/opt/mad4b-company-provisioning/reports/luxor-sharm-official-room-categories-20261121/{wayfatours,toursdev}-before.json`
- `/opt/mad4b-company-provisioning/reports/package-tier-draft-units-ui-20261121/` (selective earlier PHP/JS/CSS copies)

Note that restoring these exact source revisions requires site-specific receipts and the original post IDs/relations. Never delete or overwrite a user-created Unit post when undoing this cohort.

## 3. Pricing Lab original editor visibility

Read-only `MAD4B_Package_Stage_Sourcing_V1` was updated to count native Unit post status **separately** using actual Relation 223 JOIN to `wp_posts`. Source hotel discovery presents `published_unit_count` vs `draft_unit_count`; does not label drafts as available or priced. A collapsible original Unit edit link list was added in existing **Assignments** step with only verified capacity values; no new JetEngine form, duplicate source field or manual Rate generation approval.

As of this source inspection, original missing-destination Package stages:
- Aswan `60437` → Obelisk 4 Draft/0 published, 4 other unverified governorate hotel options.
- Luxor `60627` → Hilton Luxor 1 Draft/0 published, remaining 10 native hotel options no source Units.
- Sharm `60629` → Steigenberger Alcazar 12 Draft/0 published, remaining 42 native hotel options no source Units.
- Native `property-rates` published count zero, `transport-rates` zero. Actual tier candidates remain source-unselected until authentic assignment and contract costs.

## 4. Original train 86 / 87: cross-site station-ID collision correction

Package `60623` has separately original source-referenced sleeping-train stages:

| Original stage | Transport ID both sites | Verified published original station order | Bashtil local Station ID |
|---|---:|---|---:|
| Day 2 `sleeper_train-train-02`, Cairo→Aswan | `54552`, Sleeper **No. 86** | Departure Bashtil, through Giza, Assiut and Luxor, to Aswan on Tours.dev | Tours.dev **68930**; Wayfa **68922** |
| Day 6 `sleeper_train-train-04`, Luxor→Cairo | `54553`, Sleeper **No. 87** | Aswan, Luxor, Assiut, Giza, ending Bashtil on Tours.dev | Tours.dev **68930**; Wayfa **68922** |

Tours.dev has **5 original station entries** for each train. Wayfatours has **29 stale placeholder-looking entries** including Ramses station `54231`, but not Bashtil. Train reference website shows Bashtil in the late-2026 timetable, not Ramses. Operators must verify exact train, service date, boarding terminal and cabin fare with [Abela](https://abelatrains.com/) before updating published schedules or costs.

Root cause for initial Tours.dev missing candidate trains: old inspector hard-coded Wayfatours station ID `68922` for Greater Cairo terminal; on Tours.dev that ID is **absent**, native Bashtil is ID `68930`. Fixed source-only inspector to resolve exact native `stations` CPT **published**, canonical slug `upper-egypt-railway-station-bashteel`, exact station title, and `giza-city` destination term per site. This is a narrow Greater Cairo train terminal exception, **not** countrywide hotel-city substitution and **not** numeric ID copying.

Both site now return precisely one native transport candidate for each original rail leg. Native train source remains `operator_timetable_confirmed=false`, `cabin_supplier_contract_verified=false`, `published_transport_rate_count=0`, **no checkout**. Wayfatours displays a stale Ramses terminal warning in Pricing Lab and links to operator/timetable lookup; Tours.dev candidate source shows its Bashtil native stop but cannot authorize an operator schedule automatically.

Regression added `ops/pricing-unified-checkpoints/tests/verify-sleeper-train-station-identity-20261121.php`: **21/21 PASS each site**, confirms local IDs and source route proposal, no supplier approval, no Tour Rate write.

## 5. Tests and source/control reconciliation

**Official Unit cohort**: `ops/pricing-unified-checkpoints/tests/verify-official-room-source-draft-unit-cohorts-20261121.php` → **108/108 PASS Wayfatours, 108/108 PASS Tours.dev**, no Tour Rate CPT delta.

**Existing Package source discovery regression**: **850/850 PASS Wayfatours**, **15/15 PASS Tours.dev** after category drafts introduced, no Rate mutation.

**Commercial Package generator guard**: earlier **212/212 PASS Wayfatours**, **40/40 PASS Tours.dev**; 8 Packages × 3 tiers remain blocked. **Other live regressions**: **103/103** Pricing Lab Wayfatours, **99/99** Pricing Lab Tours.dev, and **2,860/2,860** stage-destination Wayfatours (recheck if further site content moves). No positive end-to-end Tour Rate test has been claimed.

**Source branch:** Draft PR #987 `feature/pricing-unified-checkpoints-20261120`. Native  UI changes are deployed and backed up; no merge or release tag implied. The exact original Unit IDs differ by site and must remain mapped by source Property, receipt and taxonomy identity, never by numeric ID.

## 6. Remaining before genuine supplier-cost Rate generation

1. Original commercial approval mapping of **Silver/Gold/Diamond** for each actual destination hotel and each stay stage; don't auto-infer from stars.
2. Validate/source remaining hotels' room category, correct stay-city and real occupancies; obtain actual tariff, period, supplier currency, meal plan and availability. Hotels in Sharm default AI board per saved source; Cairo/Luxor/Aswan BB subject to recorded supplier policy.
3. Publish only genuinely verified Unit catalog entries when appropriate, with cost-role `property-rates`, authorized receipt, stable period and room occupancy. No default price `0` or positive artificial figure.
4. Cruise program/direction/ship/vessel/cabin/sailing source and positive real cost, plus dedicated native typed Rate-stage writer.
5. Railway sleeper train original schedule operator confirmation, cabin inventory, tariff and typed transport stage Rate writer (not generic hotel Units).
6. Re-run commercial stage guard and only then validate **new Draft Tour Rates**, Quote/Deposit, children, taxes, FX and margin. No task in this handoff fabricated a compliant commercial cost or enabled checkout.
