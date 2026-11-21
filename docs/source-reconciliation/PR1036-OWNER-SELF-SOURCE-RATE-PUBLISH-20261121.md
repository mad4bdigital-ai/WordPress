# Source-forward receipt: owner-approved, published, non-sellable Property Rates (21 Nov 2026)

## Scope and authority
Owner explicitly requested publication of company/self-provider reference costs, after approving the source values from Google Sheets `1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c`, tab Hotels, gid 0. The company is its own supplier. This is **source-value approval and editorial WordPress publication only**, not financial Cost Receipt or commercial booking authority.

## Source reconciliation
- Wayfatours initially had 56 source-anchored property-wide cost Rate Drafts; Tours.dev initially had 47.
- Nine missing source cells H9/H10, C107/H107/C108, C111/C112, and C115/C116 were tracked to four missing master Properties on Tours.dev. Four new Draft Properties and nine Draft Property Rates were created with valid local IDs and source metadata, not copied cross-site IDs. No duplicate cells.
- All 56 entries on each site were cross-checked against hotel name, exact source cell, occupancy, BB meal plan, season, USD amount and local Property link.
- Every imported Rate stores a separate `_mad4b_self_provider_owner_cost_approval_v1` record tied to the fixed source snapshot SHA-256 `d0d4b70a9b73079206bd31a58ef9af1e82e784752f2e421e8948b06d337fc920`. Signed supplier Cost Receipts and actual unit-night basis remain unverified.

## Safe publication
- Existing `wp_insert_post_data` source proposal guard is preserved. A narrowly scoped predicate admits **only** verified owner-approved company Cost references matching the source identity/value fingerprint. A modified price, source identity, meal, occupancy, season or safety flag fails closed.
- Every published source Rate retains: `mad4b_rate_price_kind_v1=cost`, `mad4b_rate_scope_v1=property`, `stop_sale=1`, `avilabile_status=0`, `_qa_disallow_public_checkout=1`, `_mad4b_supplier_sheet_quote_unapproved_v1=1`, the untouched original source proposal and its `cost_receipt_approved=false`, `nightly_basis_verified=false`, `commercial_activation_allowed=false` flags. The canonical booking providers reject a missing published Unit/validated occupancy/Stop Sale; do not bypass these gates.
- PHP module is already deployed selectively on **both live websites**, exact SHA-256 `f8e24aef64077e05befa2bfe6dbc1efd9068067dd67160f15bf8cde3cea7418d`, with the whole original legacy page and JetEngine controls preserved. This Git commit is source reconciliation, **not** an instruction to redeploy all other plugins.
- Final live WordPress readbacks: **Tours.dev 56 Published, 29 QA Draft**; **Wayfatours 56 Published, 28 QA Draft**. All 56 on each site pass owner guard, metadata locks, source receipt and unique-source-cell verification. 0 QA published; native accommodation sample returns `stay_unit_invalid` without quote/checkout; both home URLs HTTP 200.

## Regression and rollback
- The hermetic PHP test covers 13 guards: approved cost reference can publish administratively, unrelated posts unaffected, unapproved source/QA remain blocked, and price/Stop Sale/checkout flag/source cell/snapshot/approval/receipt changes revoke admission. Run `php tests/owner-self-provider-publish-hermetic.php`.
- Backups, preimages, import IDs and per-site final receipts are stored on the enrolled VPS at `/opt/mad4b-company-provisioning/reports/property-rate-publish-20261121/`.
- Original source's `_shared_generation_supplier_proposal` is intentionally retained: no conversion of per-person sheet quote into a provider-certified per-night unit cost. No tour package retail/checkout/commerce enablement was made. Approval of inventory, nightly billing basis, stage and Unit rates remains separate.

**CAUTION:** Do not interpret WordPress `post_status=publish` as a sellable quote, signed Host commercial grant, verified supplier inventory, nightly-basis approval, or authority to charge customers.
