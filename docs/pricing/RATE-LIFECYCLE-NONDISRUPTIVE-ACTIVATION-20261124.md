# Native Rate Lifecycle — Non-disruptive live audit and minimum activation, 2026-11-24

## Actual live baseline (read only)

| Dimension | Tours.dev | Wayfatours |
|---|---:|---:|
| Original `mad4b_rate_authoring_enabled` | true | true |
| Native `Shared_Product_Rate_Generation` + `MAD4B_Rate_Planner` | loaded | loaded |
| Published Tour Rates | 196, all legacy | 0 |
| Draft Tour Rates | 23 | 46 |
| Active saved automatic plan runs | 2 | 2 |
| Recorded Rate Planner runs (historical, not active) | 1,099 | 1,459 |
| Latest package plan `price_pending_no_rate` results | 64 | 65 |
| `MAD4B_Autonomous_Rate_Plan_Watchdog_V1` | active, review-only | active, review-only |
| Company margin | 20% Target Margin | 25% Target Margin |
| Signed Cost Receipt verification provider | missing | missing |
| `mad4b/package_rate_generator/approved_price` provider | missing | missing |

Original Rate Planner currently supports native input validation, draft generation, source-change-triggered automatic recheck, daily missed-check watchdog, cost source blockers, overlap detection and independently safe legacy supersession. These are **all retained**. There is no justified reason to create a new rate planner, run another cron or disable existing legacy order records.

Source's 196 published legacy rates include 100 not marked Stop Sale and 96 marked Stop Sale; these remain unchanged. They are **excluded as supplier cost inputs for new Package cost-based generation**, but are not automatically hidden from existing customer-facing legacy rate searches. Changing that behavior requires separate regression/cutover approval to protect active itineraries and booking references. Wayfatours has 46 Draft Rates; six marked `reference_retail_profit_unverified`. None are a Cost Receipt-backed new Package Rate.

## Live pilot of original planner (read-only)

Tour 60627, 2027-02-01 through 2027-02-28, `accommodation=include`:
- Tours.dev: Silver + Gold produce **two `proposal`**, not a price-authorized Draft. Wayfatours: Silver + Gold + Diamond produce **three `proposal`** due to distinct company-level assignments.
- The original Rate Guard correctly reports each of its three hotel stays as not operationally selected and lacking signed supplier-cost receipt. Pricing Lab's separate **auto-lowest candidate** remains a cost reference and is NOT an operational assignment or signed receipt.
- A plan cannot synthesize a new sale price from earlier `start_price` or old Published Tour Rates when cost authority is missing. Unpriced results are `Pending Cost` records in the plan log, NOT empty-price `tour-rates` CPT Drafts.
- Native `Shared_Product_Rate_Generation::readiness()` conservatively sets `commercial_ready=false` and `checkout_ready=false`. This is intentionally NOT a publication authorization. Any Auto-Publish without a separate signed commercial release proof would weaken the system.

## Minimum additive dependency activation

Installed `mad4b-rate-plan-company-policy-recheck-v1.php` on BOTH production code roots; current test suite `tests/rate-plan-company-policy-recheck-hermetic.php` **16/16 PASS**. It delegates into **original `MAD4B_Rate_Planner::schedule()`**, NOT another scheduler, when these source-owned option records change:
1. `mad4b_company_policy_registry_v2` — global Company Profit and policy revisions;
2. `mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1` — original owner-approved Hotel nightly unit basis;
3. `mad4b_guide_daily_base_cost_v1` — original internal guide daily cost source.

It ignores unchanged values, does not schedule on unrelated options, and respects the original `mad4b_rate_authoring_enabled` flag and actor permissions. Existing source/target price rules, Draft/Published states, Store/Bookings/Form 6, Woo Orders, independent signed original authority, cron schedules and Supersession are unchanged. Live PHP lint and all 3 native option hook registrations checked on both sites. No source data, payment or Rate were mutated.

## Next commercial release gate (NOT yet activated)

- First produce tenant/dates/tier/occupancy/board-scoped **signed Cost Receipts** with independent supplier and inventory evidence.
- Resolve automatic price-default Property vs operational Property: original Stage Guard still demands `selected_property_id`, so the Pricing Lab auto-lowest estimate does not by itself unblock commercial generation. Preserve the current block until a signed cost receipt can securely bind the calculated Property without falsely asserting a booking.
- Build verified entire-Package component composition including transport, guide, attractions, cruises/trains when relevant, and FX; apply the company Target Margin ONCE.
- Register a genuine evidence-backed `mad4b/package_rate_generator/approved_price` provider; use the existing generator to create a new **unpublished Draft**; only an independent commercial-release authority can safely promote to Published. The original `readiness()` cannot be treated as a `true` publication gate.
- Not-ready scopes remain `price_pending_no_rate` / `Pending Cost` with structured blockers; do **not** create empty-priced Draft Rates or copy old prices to fake completion.

**Outcome:** no feature disabled, original auto planning remains live, three missing native cost/policy change triggers restored, and Auto-Publish intentionally not falsely enabled while the signed pricing authority is absent.
