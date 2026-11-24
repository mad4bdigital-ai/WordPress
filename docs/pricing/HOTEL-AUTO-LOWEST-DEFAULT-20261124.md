# Auto-lowest eligible hotel cost as Pricing Lab default (2026-11-24)

## Decision

The company does **not** require a fixed hotel for a Package Stage/Tier. For a given travel start date, eligible tier, destination, date-scoped season, exact occupancy, meal plan and currency, use the **cheapest source-matched Property Standard Cost Reference** as the read-only **pricing default**. If no qualified reference exists, leave that stay unpriced rather than assigning an incompatible property. An explicit existing Operations Property selection remains an override, subject to independent checks. This does NOT book the property, confirm live stock, approve supplier receipts, write to tour source bindings, enable checkout, or generate new Tour Rates.

## Implementation and safety

- Existing `MAD4B_Package_Hotel_Stage_Cost_Preview_V1` already filtered candidate Properties using original Stage/Tier, destination, dated season, source-proven `per_person_night` Unit, party occupancy, meal and currency. It now resolves the minimum **after** those checks, deterministic ascending cost / Property ID / Rate ID.
- `pricing_default_property_id`, `pricing_default_rate_id`, `pricing_default_cost`, `pricing_selection_mode=automatic_lowest_cost_reference`, and `pricing_property_id` are computed per stage for Pricing Lab. `selected_property_id` in the original binding remains null. No writes to rates, stage source binding, Fluent Form 6 or WooCommerce occur.
- The automatic default now contributes to **preliminary_partial_total** and is covered by the existing one-time global profit policy preview. Exact original status: **unapproved cost-reference only**. All cost receipts still `false`, `approved_price=null` and `checkout_ready=false`. A persisted Operations override takes precedence and remains independently validated.
- Reference cost rates are still `stop_sale=1`. Do not label this an approved commercial selling price. The separate `MAD4B_Package_Stage_Rate_Guard_V1` still needs full supplier/stock/pricing admission for generating a *commercial* Tour Rate. It must not read a pricing default as a supplier reservation or signed approval.
- The admin Pricing Lab shows an **AUTO-LOWEST COST DEFAULT** label on the chosen candidate and keeps alternative Costs visible.

## Verification

- Original tests: `tests/package-hotel-stage-cost-preview-hermetic.php` **16/16**, `tests/hotel-cost-nightly-basis-hermetic.php` **14/14**.
- New test: `tests/hotel-auto-lowest-pricing-default-hermetic.php` **12/12**: sorting, stable ties, bad/missing/negative/nonfinite costs and missing identifiers.
- Live on Tours.dev and Wayfatours: Gold 2027-02-01, two adults, one Double room: 17/19 auto-lowest stage pricing defaults, 2 unpriced, **zero persisted stage Property IDs**. Test Package 57562: two Cairo stays USD 600 + USD 200 = USD 800 indicative hotel cost; margin-once preview **USD 1,000 Source / USD 1,066.67 Wayfa**. No checkout, rate generation, cost receipts or payment changes.
- Source Silver 2353 test Package 57562: six eligible cost alternatives on each Cairo stage; minimum USD270 and USD90 for a USD360 preliminary hotel subtotal; manual hotel selection unnecessary for preview.
- Missing travel date and unresolved child cost both produce no automatic priced default. Syntax, WordPress CLI smoke and SHA parity of both live code files passed. Production authenticated browser visual QA remains to be run.
- Two unresolved Gold reference gaps in cohort: Tour 60437 Aswan BB, Tour 60629 Sharm AI. Commercial booking readiness remains separate until qualified sources and complete Cost Composition exist.
