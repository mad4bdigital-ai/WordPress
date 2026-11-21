# Native Package Tour Rate generation — stage destination and cost-source admission

**Execution date:** 21 November 2026, Africa/Cairo
**Source PR:** Draft #987 (`feature/pricing-unified-checkpoints-20261120`)
**Live runtime:** both `https://wayfatours.com` and `https://tours.dev.mad4b.com`.

## User requirement

The Price Lab had already become **stage-specific**: candidate hotels match a stage's exact destination and Silver/Gold/Diamond tier, with Cairo/Giza limited to the saved source discovery and a separate stage for each return to Cairo. The independent commercial Rate generator still allowed a broader Tour-wide Property pool and earlier retail reference amount to create a new Draft Tour Rate. This change prevents those paths from silently bypassing the individual stay-stage source requirements.

## Source ownership and live paths

**Canonical active production source:**
- `wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/package-stage-rate-guard-v1.php` **new**, native `MAD4B_Package_Stage_Rate_Guard_V1`.
- `wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/rate-generation.php` integrated at native `prepare()` and the locked `create()` path.
- `wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/rate-planner.php` integrated at proposal creation and source evidence digest.

**Live files:** `/var/www/html/wp-content/mu-plugins/shared-product-core/pricing/{package-stage-rate-guard-v1,rate-generation,rate-planner}.php` on both companies. On Tours.dev `mu-plugins/shared-product-core` is a symlink into the active `mad4b-commerce/legacy-mu-tree`; Wayfatours has the direct standalone MU tree. Only narrowly scoped live patches were used to retain runtime improvements not yet identical in Git; no full older Git copy overwrote the live files.

**Review UI source:**
- `wp-content/mu-plugins/mad4b-package-pricing-lab-readiness-v1.php`
- `wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js`
- `wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.css`

**Regression:** `ops/pricing-unified-checkpoints/tests/verify-package-tour-rate-generator-stage-guard-live.php`

## Guard contracts

For native Tour Rates whose canonical Tour is categorized as `tour-types:packages`, no default mode, direct retail seed, manual accommodation, auto accommodation or excluded accommodation mode may bypass the guard. Other Tour types return **not applicable** and retain the exact prior pricing fingerprint and source digest.

The guard is independent of the Pricing Lab admin class and reads original source data, not a client-provided candidate list or draft Rate:

1. Canonical `tours-and-activities` via WPML, native `package_levels` term (no inferred tier).
2. Original Tour `_mad4b_package_accommodation_source_plan_v1`, `stages[]` with exact `stage_key`, day offset, nights, destination and lodging kind. Missing plans **fail closed** on any Package Tour, including Tours.dev.
3. Editorial `_mad4b_package_stay_verified_room_program_links_v1` with exact source-plan SHA-256 equality. Missing, duplicate or stale stage bindings fail. No stage nights may be missing, overlap sequentially or lie outside the itinerary.
4. For hotel/resort: native `properties` publication; exact stage destination taxonomy and native `package_levels` plus `property-types`. Cairo allows Giza only if source discovery has both canonical IDs and city-scope evidence. Aswan, Luxor and Sharm use canonical local destination slugs. Country-wide `Egypt` is not an accommodation city.
5. Both detailed `room_candidate_refs` and plan-only Property alternatives are checked for destination/tier/type mismatches. A saved invalid alternative prevents commercial admission, even if another valid candidate exists.
6. Original Tour itinerary `itinerary_item` is checked for explicit hotel overnight city. A direct overnight contradiction **blocks**; missing explicit overnight evidence remains a *visible editorial candidate* in Pricing Lab but **cannot authorize generator use** until reviewed.
7. Each hotel stage requires an explicit selected Property from that stage's tier candidates, specific bookable `units` belonging to this Property through native JetEngine Relation 223 and original room source receipt. Positive Unit capacity and actual availability are separately required.
8. Cost approval cannot be inferred from `unit_start_price`, QA fixtures, Draft Property Rates, or retail Tour Rates. Any received approval must reference a published, non-QA, cost-role `property-rates` source bound to the selected Property and Unit and cover the **entire shifted stay date interval** requested by Rate Planner.
9. Cruise stage requires its native original program duration/direction, vessel, cabin, sailing and supplier receipt. Since the generic hotel-stage Repeater cannot encode a cruise program/cabin, **`cruise_rate_stage_native_provider_not_connected`** remains a hard blocker until the dedicated native provider is wired.
10. Sleeper train stages are likewise distinct from hotel stages, and remain blocked by **`sleeper_train_rate_stage_native_provider_not_connected`** until their original transportation/cabin provider is wired.

No stage may borrow the previous stage's Property, Unit, cost or date authority. Repeating the same Cairo candidate hotel at the end of the journey is legal editorially; it does not reuse the original booking/price.

The guard returns per-stage reasons, e.g. `hotel-luxor-02:stage_destination_has_no_candidate_property`, and a digest of canonical source, source bindings, selected tier and requested Rate window. Native `prepare` returns `WP_Error('rate_generator_package_stage_source_incomplete')` when any stage fails. The `create` method executes `prepare` again and revalidates the same source digest *inside the native generation lock*, before reusing or inserting any Rate.

**Important:** This is safe admission control; it does **not** invent complete commercial rate/stay-stage writers for cruise, cabin, train or Unit supplier costs. New Package Rates remain ungenerated while those native provider contracts are missing. Any future positive generation must be validated against the correct native stage writer, unit cost receipts and checkout gates separately. No previous Tour Rates, bookings or orders are mutated.

## Native Rate Planner

`MAD4B_Rate_Planner::plan()` always records `package_stage_review` for Package candidates, even when no selling-price source exists or accommodation mode is `exclude`. While blocked:
- `input = null`, `state = proposal`
- `reasons` include `package_stage_source_incomplete`
- No `Shared_Product_Rate_Generation::create()` call may occur for that candidate
- Rate Planner saves original stage-level reason codes and a source digest as an operator review
- Its `persist` workflow re-plans from native data before any writes; the existing no-price Rate emitter also refuses empty Drafts.

For **non-Packages**, the original six-field `evidence_digest` is preserved byte-for-byte — no appended null stage or source key and no new Rate identity. The direct native generator also preserves its existing fingerprint for non-Package inputs.

## Exact live evidence

- Wayfatours **8 canonical Package Tours × 3 tiers = 24 reports**, **84 original stage/tier inspections**. **All 24 remain blocked** because authentic Property/Unit selections, supplier receipts, departure/source programs, and destination-specific Aswan/Luxor/Sharm hotel options are not complete. Spanish Tour `60661` resolves to canonical EN Tour `60623`.
- Wayfatours native generator regression: **212/212 PASS** including original `prepare`, rejected `create` calls, manual and auto modes, `accommodation=exclude` Rate Planner preview, native WPML, non-Package compatibility and DB Rate-count invariance. Tour Rates count **108 → 108** in this test.
- Tours.dev native generator regression: **40/40 PASS**. The tested Package source has no saved stage source plan and fails closed; Tour Rates count **228 → 228** in this test.
- Non-Package native Rate generation `prepare` succeeds on example Day Tours (`60579` on Wayfatours and `64850` on Tours.dev), and Rate Planner original six-field source digest equality verified for non-Packages.
- Existing admin Pricing Lab regression **103/103 Wayfatours**, **10/10 Tours.dev** PASS. Existing stage destination inspection **2,860/2,860 Wayfatours** and **5/5 Tours.dev** PASS. PHP syntax and JavaScript syntax PASS.
- Pricing Lab AJAX via actual WordPress Master Admin user context shows `native_generator_stage_admission` read-only blockers for each selected Package/tier, while `supplier_cost_total = null`, `generated_rate_ready=false`, `checkout_ready=false`. The Lab has no date-window selector, so its readback also lists `travel_date_window_missing`; Rate Planner evaluates the selected generation window.
- No create operation produced a Rate, altered a saved source plan, or affected orders during tests.

## Risk/limits and remaining work

This change **does not make the eight Packages ready for generation**. It makes it impossible for the native writer to generate them through a generic Tour-wide hotel list or unqualified selling-price seed.

Remaining before NEW Draft Tour Rates:
- Confirm explicit selected Room/Unit for **each separately dated stay** at the original destination and tier, including Aswan, Luxor and Sharm stages.
- Capture genuine date-scoped positive cost sources, currency, room occupancy, board basis and availability. The currently linked 134 source Units still do not qualify as positive cost sources.
- Connect native cruise vessel/program/cabin/sailing provider and sleeper-train cabin/transport provider to the actual Rate writer (not the generic hotel-stage Repeater).
- Resolve source itinerary overnight-review items, guide/nights, children, taxes, company policy and other supplier approvals.
- Only after verifying these original stage contracts, enable staged Draft generation and complete end-to-end Quote/Deposit/Checkout checks. Existing historical order-referenced Draft Rates remain intact.

**Source convergence:** exact source code edits are on Draft PR #987. CI must be checked by its own latest GitHub HEAD and is not asserted green by these live runtime checks.

**Rollback:** root-only selective original live generator and planner files plus incremental copies are under `/opt/mad4b-company-provisioning/reports/package-rate-stage-native-gate-20261121/`, and the pricing UI prior versions are saved there. The original live files were preserved before applying the minimal patch. Removing the new native guard requires reversing its `require_once` in the generator; simply deleting the guard file without restoring the original generator is not a valid rollback.
