# Native Property Standard / optional Unit Rate UI and 19 Package hotel/resort stages

Updated 2026-11-23. **Live selectively installed on Tours.dev Staging + Wayfatours Production; price and checkout gates remain closed.**

## One source, not a new CPT

Existing JetEngine CPTs: `properties`, `units`, `property-rates`.

- Original `property-rates` list gets **Property Standard Rates** and **Unit-Specific Rates** filter views (native meta filters) and truthful scope/Property/Unit/amount-role columns. Unclassified and Draft rows remain visible in All.
- Native Property and Unit editors get an explicitly user-triggered, nonce/capability-protected AJAX **create Draft** action. It creates only a new `property-rates` **Draft**, with original Property ID, optional Unit ID, native scope, `stop_sale=1`, `avilabile_status=0`. No amount, selling authority, confirmed inventory, supplier Cost Receipt, public catalog activation or WooCommerce order.
- Unit-to-Property mapping is verified against original JetEngine relation 223, exactly one parent. The WordPress editor has no nested HTML forms. Role gate is original `commerce_manage_pricing` plus `edit_commerce_rates`.
- `MAD4B_Stay_Layers_Admin_V1` remains the original direct-acquisition / Rate-selling editor; there is **no second Rate Post Type or pricing source**.

## Per-stage source cost (Pricing Lab)

- Original `MAD4B_Package_Pricing_Lab_Readiness_V1::inspect` and `mad4b/package_lab/inspected_report` filter are reused; no duplicate editable Package or pricing store.
- Tour start date, rooms, adults, children and source currency feed read-only per-Stage calculation and visible stage table. Every hotel/resort stage has canonical `stage_key`; duplicated keys cannot be included twice. The original Tour plan gives start day, destination, nights, and native tier/candidate eligibility.
- **Unit Rate first** when one real, originally linked, published Unit is explicitly selected; otherwise **Property Standard cost Rate** with `prop_related_unit_id=0` and `mad4b_rate_scope_v1=property`. Explicit multiple Units are held for allocation review. An unselected Unit never blocks Standard cost-source discovery.
- A candidate Property is **not** a selected Property. A selected Property must belong to the exact stage/tier candidate list, have a native published Property identity, and pass the original seasonal assignment policy if available. No arbitrary city/tier fallback.
- Source amount must be a native published `cost` Rate in the requested currency covering the whole stage, with a unique matching scope. Synthetic/QA-only records and selling Rates are excluded. `stop_sale=1` source references may be displayed as **preliminary review only**, never certified or checkout-eligible.
- `per_room_night × nights × rooms`, or `per_person_night × nights × adults`, are computed only when the explicit Rate basis permits it. Ambiguous `per_person`, `per_stay`, unknown currency/FX, unresolved children/infants, invalid dates and conflicting rates produce **no monetary total**. Meal-plan confirmation remains pending, and signed supplier Cost Receipt / stock confirmation remains a separate gate. The sum of preliminary stages is a **PARTIAL subtotal**, never an `approved_price` or verified complete Package quote.
- Original Package Stage Guard now does not reject absent Units and treats a cruise vessel as a native Property (without requiring a Cabin Unit); original provider blockers for real cruise sailing/availability/cost and sleeper trains remain intact. A Property Standard source still requires party-specific signed cost re-verification before commercial generation.

## Live census and limits

Canonical 8 Packages per site: `57562, 60437, 60439, 60441, 60443, 60623, 60627, 60629`. Original source contains **19 unique hotel/resort stage keys** per tenant, or **57 theoretical Silver+Gold+Diamond stage×tier combinations**.

| Live site | Active tier-scoped hotel/resort instances inspected | Blocked by original Package Level policy | Selected hotel instances | Preliminary costs | Commercially confirmed |
| --- | ---: | ---: | ---: | ---: | ---: |
| Tours.dev | 40 | 17 | 0 | 0 | 0 |
| Wayfatours | 53 | 4 | 0 | 0 | 0 |

The unavailable tier instances are **not** activated by this source/UI change. Current original policy does not assign Diamond to seven source Packages; Wayfatours has two blocked tier selections (Silver 57562, Diamond 60623). Tenant policy differences are preserved, not equalized without Owner intent. Direct native SQL rate census: **56 published Property Standard Cost References on EACH site**, all 56 per site with `stop_sale=1`. Tours.dev additionally has **29 Draft Unit-linked Rates**, Wayfatours **28 Draft Unit-linked Rates**. No published Unit-linked Rate on either site. A prior post-list query wrongly returned zero Wayfatours published Rates; this direct CPT SQL count is authoritative. Published Stop-Sale references are not saleable or automatically supplier-approved.

## Verification & rollback

- `tests/property-rate-scope-ui-hermetic.php`: **10/10** original list/query/filter/permission checks; **11/11** standard-draft AJAX and **11/11** unit-draft AJAX source-safe writes in mocked WordPress.
- `tests/package-hotel-stage-cost-preview-hermetic.php`: **16/16** calculation, source precedence, ambiguity, FX, dates, children guards.
- `tests/package-stage-guard-property-standard-hermetic.php`: **10/10** Optional Unit vs original source-cost authority and cruise/sleeper negative gates.
- Prior original `tests/accommodation-rate-source-fallback-hermetic.php`: **23/23**.
- Prior `tests/package-property-standard-no-unit-hermetic.php`: **11/11**, including train 87 Luxor segment and wrong sailing rejection.
- PHP 8.5 lint clean; original Pricing Lab JS syntax clean. WordPress class/hook/source inspection passed on both sites. Browser-authenticated end-to-end visual UI validation **not performed**. No actual Draft was created in Production for QA.
- Per-site exact SHA backups and selective file receipts: `/opt/mad4b-company-provisioning/reports/native-rate-scope-hotel-stage-20261123/{source,wayfa}-before/`, `{source,wayfa}-receipt.json`, `mobile-table-followup.json`, `draft-button-ajax-fix.json`, `stage-key-dedup-followup.json`.
- Live mutation: only two new MU plugins, original Pricing Lab JS and original Package Stage Guard, plus bounded follow-up improvements. No original `Property Rates`, `Units`, Tour plans, supplier costs, Stripe, WooCommerce Orders or checkout configuration changed.

## Remaining commercial closure

1. **Business selection:** An Operations-selected Property per exact stage and allowed tier, with season/availability source evidence. The current original source has zero selected Properties; do not auto-pick an unconfirmed candidate merely to show a price.
2. **Commercial rate basis:** Verified company Self (or explicit external supplier) cost reference, room/person/nights and board, child/infant policy, currency/FX and date window; original source shows unconfirmed and Stop Sale price references.
3. **Signed stage Cost Receipt:** Independent actual-cost document, source hash, occupancy and live availability and approval, then unique stage-key composition into Package Rate Generator without duplicated costs; preserve separate cruise/sleeper provider requirement.
4. **Release and browser acceptance:** UI browser review, stage-tier Save/Readback in a guarded authoring workflow, quote/deposit/checkout QA, then separate Release authorization. This change deliberately does not authorize Rate publication or booking.
