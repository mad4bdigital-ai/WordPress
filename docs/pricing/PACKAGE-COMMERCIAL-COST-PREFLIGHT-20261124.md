# Commercial Package Cost Composition — First-stage implementation, 2026-11-24

## Scope and first pilot

- Original eight canonical Package tours: 57562, 60437, 60439, 60441, 60443, 60623, 60627, 60629.
- Original overnight plan inventory: **19 hotel/resort stages, 7 cruise stages, 2 sleeper train stages**. No signed `supplier_cost_receipt` approvals in the 28 original stage bindings on Wayfatours. The original trust filter `mad4b/package-cost-receipt/verify-authority` has no installed successful authority provider and is **fail-closed**; code must not silently manufacture receipt signatures.
- Prioritize pilot **Tour 60627**, because its canonical itinerary has **three hotel stages and no cruise/train lodging stages**. It still requires **guide, visits, transfers/transport, dated supplier evidence, party/meal basis and company authorization** before a full approved Package price.

## Live work completed

- Added `mad4b-package-commercial-cost-preflight-v1.php` as a **read-only, original-source-stage-key** ledger. The Pricing Lab report attaches `commercial_cost_preflight`, matching the immutable accommodation source plan and original binding digest, and recording per-stage original Cost Rate IDs, automatically lowest priceable hotel candidate, dates, currency, no signed receipt, explicit missing cruise/train/supplier/service groups. It **never uses old Tour Rates** as a cost source or guesses amounts for a missing stage.
- Price totals remain **reference-only** and cannot be mistaken for Package sellable prices: `complete_supplier_cost_total=null`, `approved_price=null`, `new_tour_rate_ready=false`, `form6_final_price_ready=false`, `woo_advance_allowed_by_this_source=false`.
- Added a Pricing Lab UI detail table showing each stage's reference cost and the commercial blocker, with missing service group labels. Stage guard and original `mad4b/package_rate_generator/approved_price` signature contract remain the separate commercial price gate.
- Live Tours.dev/Wayfa pilot Gold, start February 1 2027, two adults, one Double room: same **USD 740** hotel reference total (Cairo 2 nights USD 400, Luxor 1 night USD 140, Cairo 1 night USD 200). Respective native source Property Cost Rate IDs are site-specific. No Property selection was written.
- Company global margin current remains Source 20% / Wayfa 25% Target Margin, but **not re-applied by the commercial preflight**. Existing original hotel-only margin is indicative; no rate or payment published.
- 19/19 pure ledger assertions pass with exact canonical stage keys, duplicate/orphan detection, no negative costs, missing cruise/train nonzero costs, and absence of imagined supplier admission; 12/12 Auto-Lowest + 16/16 Stage Math + 14/14 Normalized Cost guard remain pass.

## Commercial blockers to resolve next

1. Supplier **source cost receipts** tied to exact Property Rate/cell, period, occupancy/board and company self-supplier identity; independent signed authority must validate them before checkout.
2. Room allotment / supplier availability policy and the original `MAD4B_Package_Stage_Rate_Guard_V1` eligibility and overnight-evidence gates; a pricing default is not a confirmed operational booking.
3. Non-overnight cost composition for transfers, guides, visits/entrances, transport and local services. No zero-cost defaults except documented included/complementary policies with approvals.
4. Original package Cost Composition approved-price provider: when **all** components are independently certified, derive a new per-person Rate with company margin once, correct occupancy factors, and immutable source+supplier evidence binding.
5. Generate only new Draft Tour Rates. Then validate Fluent Form 6 and WooCommerce advance/remaining-balance snapshots in protected end-to-end tests, with legacy order financial history unchanged.

## Safety

Original live site source parity confirmed after SHA-locked file updates. No Tour/Property Rate, Form 6 or WooCommerce order data was written by this release. No refunds or Stripe charge attempted. Browser-authenticated visual QA still outstanding.
