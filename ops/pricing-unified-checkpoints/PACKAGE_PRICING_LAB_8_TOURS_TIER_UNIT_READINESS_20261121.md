# Package Cost & Unit Readiness in MAD4B Pricing Lab — 21 Nov 2026

## Purpose, existing Pricing Lab boundary and current rollout

User asked to review **how the candidate hotels and room/unit costs appear in Pricing Lab for each Package**. The existing native `MAD4B_JetEngine_Pricing_Lab_V1` (`wp-content/plugins/mad4b-jetengine/modules/functional-modes/class-pricing-lab.php`) is a **strategy/preset simulator**. Its `rate_ids()` intentionally reads only `publish` Tour Rates and `preview()` compares existing selling rates; it never composes supplier costs from hotel/cabin/transport components. It cannot be used to assert package supplier cost completeness.

A separate **read-only Package Cost & Unit Readiness** inspection section was added to that admin page, without editing Pricing Lab's existing strategy class or JS. URL: `https://wayfatours.com/wp-admin/admin.php?page=mad4b-pricing-lab`. Each canonical Package can be selected, then one of three native `package_levels` tiers Silver (2353), Gold (2354), Diamond (2356). Only native source data is read. It shows stages, nights, destination, hotel alternatives, linked native unit categories, occupancy, unit availability, declared cost-field status, published non-QA Property Rates, source-plan warnings and original edit links.

Source files, committed to Draft PR #987:
- `wp-content/mu-plugins/mad4b-package-pricing-lab-readiness-v1.php`
- `wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js`
- `wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.css`
- `ops/pricing-unified-checkpoints/tests/verify-package-pricing-lab-readiness-live.php`

**Live runtime:** installed this read-only MU plugin + assets manually on Wayfatours and Tours.dev with no site option or post data writes. Tours.dev has **zero saved Package source plans** as of this check; its panel displays a neutral no-plans message rather than fabricating matches. Wayfatours has **eight canonical EN Package source plans** and EN+ES translations. Source code remains on Draft PR independently of live installation.

## Original source data and non-duplication

Authoritative input sequence:
1. `tours-and-activities` `_mad4b_package_accommodation_source_plan_v1` → stage geometry, destinations, nights and tier candidate hotel IDs.
2. `_mad4b_package_stay_verified_room_program_links_v1` → editorial hotel candidate/unit references and cruise program sources. These are **editorial candidates**, not selected bookable units, commercial approvals or cost receipts.
3. Actual `properties`, `units`, native JetEngine Property→Units Relation **223** → validate every candidate room against its Property and source receipt.
4. Original Unit meta `unit_start_price`, `unit_base_currency`, `unit_direct_price_unit`, `unit_direct_price_kind`, `unit_available`, `unit_max_occupancy` plus published `property-rates`, excluding `_booking_experience_qa_pricing_fixture` and `_qa_disallow_public_checkout`.
5. Existing `related_tour_id` scalar postmeta → count surviving historical Draft Rates only. **Never** substitute their retail prices for supplier costs. Parent Tour `tour_queried_rates` is still the sole existing Rate inventory; no duplicate JetEngine Relation, meta field, or derived Rate list was created.
6. Existing Package Components readback → guide/transport informational status where available.

No Price, Rate, supplier binding, payment, quote, booking, order, catalog state, pricing strategy or Checkout changed. `supplier_cost_total = null`, `sale_price = null`, `margin = null`, `generated_rate_ready=false`, `checkout_ready=false`. Even a future positive `unit_start_price` marked cost remains a **declared field**, not an approved supplier Cost Receipt. Hotel direct price and QA Draft rates are not accepted as automatic supplier cost.

## Grounded Wayfatours inventory snapshot

The eight source Package Tours are `57562, 60437, 60439, 60441, 60443, 60623, 60627, 60629`.

| Scope | Snapshot |
| --- | ---: |
| Canonical EN Package Tours | 8 |
| Tier combinations checked | 24 (8 x 3) |
| Unique eligible editorial candidate Properties across tiers | 18 |
| Unique native linked Unit posts | 134; all `publish` |
| Unit categories with explicit positive occupancy | 56 / 134 |
| Units flagged available | 0 / 134 |
| Units with positive direct starting price | 0 / 134 |
| Unit fields with positive recorded internal Cost role | 0 / 134 |
| Candidate Properties with matching published non-QA Property Rates | 0 |
| Saved/staged Package nights/segments | 28 editorial stages in the 8 source Tours |
| Hotel/resort stages | 19 (16 Cairo, 1 Aswan, 1 Luxor, 1 Sharm) |
| Cruise stages | 7 |
| Sleeper train overnight segments | 2 |
| Hotel/resort stages with no hotel candidates | 3 (Aswan, Luxor, Sharm) |
| Guide service days currently unreviewed | 66 |
| Native Rate stay/transport stage repeaters written from these plans | 0 |
| Approved supplier stage cost receipts | 0 |
| Property and native Rate identity mismatches in the 24 tier previews | 0 |

These **tier inventory pools repeat across several Packages**; do not sum per-tour candidate counts into unique hotels. Per tier, the source pools are:

| Tier | Unique candidate hotels | Unique linked Units | Unit occupancy present | Unit prices available |
| --- | ---: | ---: | ---: | ---: |
| Silver | 5 | 30 | 8 | 0 |
| Gold | 6 | 45 | 21 | 0 |
| Diamond | 7 | 59 | 27 | 0 |

Each Package contains two Cairo hotel stages using the same tier pool, so its native tier display typically shows **10/12/14 hotel mentions** (five/six/seven alternatives repeated for two different dates), not new hotels.

### Per-Package differences to display

| Tour ID | Days / Nights | Stage content | Missing accommodation candidates in all three tiers |
| --- | --- | --- | --- |
| 57562 | 8 / 7 | Cairo 3n → Nile cruise 3n → Cairo 1n | None in hotel stages; cruise ship/cabin unpriced |
| 60437 | 8 / 7 | Cairo 2n → Aswan hotel 1n → Nile cruise 3n → Cairo 1n | Aswan |
| 60439 | 7 / 6 | Cairo 2n → Nile cruise 3n → Cairo 1n | None in hotel stages; cruise unpriced |
| 60441 | 10 / 9 | Cairo 3n → Nile cruise 4n → Cairo 2n | None in hotel stages; cruise unpriced |
| 60443 | 8 / 7 | Cairo 2n → Nile cruise 4n → Cairo 1n | None in hotel stages; cruise unpriced |
| 60623 | 8 / 7 | Cairo 1n → sleeper 1n → Nile cruise 3n → sleeper 1n → Cairo 1n | None in hotels; sleeper cabin and cruise unpriced |
| 60627 | 5 / 4 | Cairo 2n → Luxor hotel 1n → Cairo 1n | Luxor |
| 60629 | 12 / 11 | Cairo 3n → Nile cruise 4n → Sharm resort 3n → Cairo 1n | Sharm resort |

All eight source plans record `company_policy_error=policy_company_binding_invalid` as **historical source-plan evidence** (recorded 20 Nov); the panel labels this as **saved plan warning**, not a re-evaluated live company policy verdict.

After user-authorized archival, 30/32 EN Draft Rates and 30/32 corresponding ES Draft Rates were safely trashed. Two EN Rates `60830` and `61060`, with ES pairs `60832` and `61062`, remain Draft because WooCommerce order references must survive. These surviving Draft Rates are **not used** as supplier cost or new retail Price in Package Lab.

## What operator sees

Select Package → select Silver/Gold/Diamond → **Inspect original sources**. Read-only summary shows stages, hotel candidate mentions, unique linked units, occupancy evidence, units with declared cost fields, available units, and stages without hotel candidates. Expand a stage and then a candidate Property to inspect every linked Unit, its capacity/availability/cost-field state and the original Property/Unit edit links. Separate notices flag cruises and sleeper-train costs that need a different supplier program. All missing prices remain **unpriced**, not EGP 0, and no rates are generated.

The existing global Pricing Lab Presets/Target Groups/Controllers/Impact Preview/Apply & History tabs and saved strategies remain intact.

## Acceptance evidence

- PHP lint PASS; Node syntax check of new JS PASS.
- Wayfatours full source inspector **103/103 WP-CLI read-only checks PASS**, across all 24 combinations and strict no-duplicate/no-price assertions.
- Tours.dev (no Package source records) **10/10 checks PASS**.
- AJAX authorized Admin readback 200 success with correct Package 57562 Silver evidence; invalid tier rejected, anonymous user rejected. WP nonce in CLI test correctly passed via both `$_POST` and `$_REQUEST` to emulate HTTP request semantics.
- Admin display hook, asset enqueue and inline config confirmed for Master Admin and administrator on Wayfatours; source site handles no-plans state.
- Verified native JetEngine Property→Units Relation 223 against candidate `_mad4b_room_source_receipt_v1`: **zero mismatches**. An initial SQL quoting bug in the candidate-only check was caught and corrected before live deployment.
- Exact manual live files copied to both MU-plugin locations; original code prior to last source-warning refinement backed up root-only under `/opt/mad4b-company-provisioning/reports/package-pricing-lab-v1-20261121`.

**Remaining acceptance:** Actual logged-in web browser visual/interactions were not operated in this check; WP-CLI admin rendering and AJAX validate the server wiring, but do not prove screenshot layout, JS click flow or Save in a signed-in browser. This panel itself has no Save action.

## Remaining work before Price generation

1. Resolve missing Aswan, Luxor and Sharm hotel/resort candidate stage scopes (three specific source stages), with destination-aware and tier-aware alternatives.
2. Verify Unit occupancy/capacity (78 not recorded), availability/inventory and room–Property relations for all 134; preserve source evidence and avoid fake capacities.
3. Assign **real date-scoped supplier Cost evidence** per selected Unit: currency, cost-vs-sell, price basis, board/meal plan and guest occupancy. Self is fallback, but fallback without its own verified cost data is *unpriced*. Do not use QA-only Property Rates. Hotel general default BB, and verified Sharm/Hurghada hotel exceptions may use AI.
4. Bind cruise sailings/ship cabin-unit pricing, and the two sleeper-train segments; determine supplier terms and Cost Receipts.
5. Review guide days, route transport stages, children/infants, financial company policy, FX, tax, margin and inclusion-vs-addition to selling Rate.
6. Only when the originally authoritative providers are ready, generate NEW native Tour Rates as Draft, attach native stay/transport stages, preview costs by date/tier/occupancy and validate Quote/Deposit/Checkout separately. Do not publish or reuse archived retail rates.
