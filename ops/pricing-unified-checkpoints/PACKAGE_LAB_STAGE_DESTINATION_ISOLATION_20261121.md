# Package Pricing Lab — Stage Destination Isolation

**Applied selectively to live Tours.dev and Wayfatours on 21 November 2026.** Source file changes are tracked in Draft PR #987. Scope is the existing **read-only Pricing Lab source inventory**; this work does not create, update, publish, approve, or delete Tour Rates, Property Rates, supplier Contracts, WPML terms, cost receipts, Woo orders, or bookings.

## Why

The eight source Packages each have two separate Cairo hotel stay stages (trip arrival and return). The same **candidate hotel pool** can legitimately appear in both when the original package discovery explicitly includes both Cairo and Giza. But a repeated candidate Property **is not a reused booking**, stay assignment, room, availability or nightly tariff.

Three additional original stay stages require separate destination-specific hotels:
- **60437** Aswan hotel 1 night.
- **60627** Luxor hotel 1 night.
- **60629** Sharm el-Sheikh resort 3 nights.

All three still have **zero** hotel candidate Properties for Silver, Gold and Diamond; do not substitute Cairo pool.

## Contract enforced

Within `MAD4B_Package_Pricing_Lab_Readiness_V1::inspect()`, the original saved `_mad4b_package_accommodation_source_plan_v1` provides each stage's exact `stage_key`, `destination_key`, `source_day_start`, `nights`, `lodging_kind`. Original `_mad4b_package_stay_verified_room_program_links_v1` is the editorial Property and Unit candidate list.

For each hotel or resort candidate, the server validates these **independently**:
1. Property exists as native published `properties` post.
2. Property has matching **canonical `destinations` term(s)** for *that stage*, never only a country-level `egypt` term or title keyword.
3. Property has the requested exact native `package_levels` term (Silver / Gold / Diamond).
4. Property has actual `property-types` `hotel` or `resort`, and stage is a hotel/resort stage.
5. Stage itinerary has no explicit contradictory hotel overnight and day/night bounds fit the original `itinerary_item`.

Accepted destination taxonomy mapping:
- `cairo`: `cairo` and, **only when supported by that Tour's saved source discovery `city_scope` plus `source_classifications.en`**, `giza`. The eight source Packages have this explicit Cairo/Giza regional scope; this does not create a global rule for every Tour.
- `aswan`: canonical `aswan-gov` or `aswan-city` term.
- `luxor`: canonical `luxor` term.
- `sharm`: canonical `sharm-el-sheikh` term.
- Other cities: exact destination taxonomy slug only; unmapped cities fail closed until configured.

A candidate that fails stage destination/tier/type/published status is **excluded from selectable editorial candidates**, with reason preserved in `excluded_candidates` and exposed in the UI. It is never priced. A stage explicitly contradicted by the original itinerary, or whose nights exceed itinerary boundaries, **blocks all its hotel candidate qualification**, even if saved plan says otherwise. Missing explicit overnight evidence is a **review task**, not an invented mismatch; while editorial candidates can remain visible, no commercial approval is issued.

The original `itinerary_item` text is read for explicit **`overnight in [city]`** on each hotel night. It is never inferred merely from sightseeing destinations, tourist attractions or a flight headline. Positive evidence is independent of vendor tariffs/room assignments. The inspector returns each day title and `match/unconfirmed/conflict` plus stage result. This check is intentionally conservative; `overnight at Red Sea Resort` does not independently confirm `Sharm el-Sheikh`.

Every stage separately carries stable stage-specific identity:
`sha256([tour ID, Package tier, stage key, start day, nights, destination])`,
and arrival / departure day numbers, so two Cairo visits are visible as independent stays even when Property candidates overlap. **This is a read-only audit identity, not a new Rate meta field or an already implemented downstream generator contract.** Original authoring and native Rate generation remain separate.

Cruise and sleeper train stages do **not** inherit city hotel pools. Their own ship/program/cabin/transport suppliers remain unpriced pending separate evidence.

## UI and editor navigation

Existing admin route: `https://wayfatours.com/wp-admin/admin.php?page=mad4b-pricing-lab`, under **Package Cost & Unit Readiness**. Choose Package then Silver, Gold or Diamond.

For each stage, show destination acceptance scope, day range, stage key, itinerary overnight evidence and any excluded source candidates. When city recurs, explicitly state that dates, occupancy, supplier tariff and booked Unit are **not** reused. The top summary distinguishes 288 candidate stage mentions from 18 unique Properties and has stage-review/exclusion counters. The stage viewer still lists all 134 original linked Unit records, with no retail cost substitution.

Tour editor's existing **Package Components** box now deep-links to the **same canonical English Package** in Pricing Lab (`mad4b_package_tour`), including from WPML Spanish Tour edits. Pricing Lab only accepts query selections from its already-authorized server package catalog; the AJAX independently checks Tour edit permission and tier. This restores Master Admin navigation using the same permissions as native Pricing Lab, without opening broader WordPress privileges.

## Live acceptance

**Wayfatours**: 8 Packages × 3 tiers = 24 previews, **2,860/2,860 read-only destination/itinerary/identity checks PASSED**. All 18 eligible candidate Properties and 134 Units preserved. No wrong destination or tier candidate admitted; zero false candidate exclusions on current data. All 24 returning-Cairo tier pairs have different stage keys and start days. Red-team negative controls: Cairo Property rejected from Aswan, Luxor and Sharm; Silver rejected from Gold; hotel rejected from Cruise; explicit Luxor overnight on synthetic Cairo stage blocks candidates; stage night range beyond original itinerary blocks candidates. Existing Package Pricing Lab read-only suite **103/103 PASS**, and Package Components editor **31/31 PASS** after updating an outdated historical Rate-count assertion following the authorized archive of legacy Drafts. Source Tours.dev 5/5 destination test, 10/10 Pricing Lab and 20/20 Tour editor tests passed.

Inspecting 57 hotel/resort stage–tier combinations reveals **27 combination reviews** (nine distinct stage source instances across all three tiers) with incomplete explicit overnight-in-city textual evidence. These are content verification tasks, not verified destination mismatches. No explicit itinerary conflict exists in the current eight source plans.

Live role test: Master Admin is authorized to see the panel; authorized nonce-protected AJAX on 60627 and 60629 returns destination scopes, stage boundaries, itinerary results and no supplier price (null). HTML admin panel/asset enqueue tested. No end-to-end logged-in browser screenshot/interaction was performed; JS passed Node syntax check and PHP passed lint. The source site has zero stored Package source plans; its panel reports this rather than fabricating source data.

### Existing gaps, unchanged

- Source Plans have not been commercially approved and have stored `policy_company_binding_invalid` warnings, **not a newly established live company policy verdict**.
- Aswan/Luxor/Sharm candidate hotels and unit pricing pending.
- 134 original Units: 78 lack recorded positive occupancy; all `unit_available=false`, all without positive direct cost amounts; no matching published non-QA Property Rates.
- Cruise vessel/program/cabin supplier pricing, sleeper train, guide days, children and taxes remain separately incomplete.
- All supplier cost totals remain `null`, `generated_rate_ready=false`, `checkout_ready=false`; no new Rates generated.

## Files and rollback

Changed existing files:
- `wp-content/mu-plugins/mad4b-package-pricing-lab-readiness-v1.php`
- `wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js`
- `wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.css`
- `wp-content/mu-plugins/mad4b-package-components-editor-context-v1.php`
- Existing package editor regression smoke updated for archival population.

New read-only test:
- `ops/pricing-unified-checkpoints/tests/verify-package-pricing-lab-stage-destinations-v2.php`

Live rollback copies are root-only (0600) under `/opt/mad4b-company-provisioning/reports/package-lab-destination-v2-20261121/`; original V1 files saved prior to V2 deployment and intermediate copies saved before final guarded/deep-link changes. No data rollback is needed because no commercial database state was modified.

## Risk boundary

This change validates destination scopes in the **source readback / admin Pricing Lab eligibility surface only**. It does NOT automatically enforce the same exact stage invariant inside the separate commercial Tour Rate generator (`shared-product-core/pricing/rate-planner.php`) or turn unpriced supplier candidates into quoted/package costs. Before generating fresh sellable Rates, the original generation path must also consume stage-specific source/validity/date/cost receipts and reject cross-destination supplier assignment, with E2E coverage. Pricing Lab does not claim otherwise.
