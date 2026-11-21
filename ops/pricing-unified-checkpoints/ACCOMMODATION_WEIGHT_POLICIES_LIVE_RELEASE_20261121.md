# Accommodation Weight Policies — source-backed live selective release (21 November 2026)

## Delivery

New MU component was authored in this branch and selectively deployed to BOTH original WordPress environments:

- Source: `https://tours.dev.mad4b.com`
- Target: `https://wayfatours.com`

Both use the original JetEngine `properties`, `units`, Relation 223, and `property-rates`. No new property or rate CPT, external supplier price, publishing gate, checkout, or live tour price was touched by this delivery.

**Source/live artifacts:** `wp-content/mu-plugins/mad4b-accommodation-weight-policies-v1.php`, `wp-content/mu-plugins/assets/mad4b-accommodation-weights-v1.js` and `.css`. Both sites have matching deployed SHA256:
- PHP: `737ed3895e0b1977b944266898d62a1e836e52daaf64c6caa173abc696a2b50c`
- JS: `5d667c4929a5505936b95129e64b681e87d73f08d3d41279792b8f62eed4eee4`
- CSS: `7744ebad38b5654e68efcf312a9047d154540a90fb7b3a9b1b308402d0105c5a`

Native admin entry: `/wp-admin/admin.php?page=etg-dynamic-pricing-controls`, inside the existing Accommodation & Unit Pricing panel; the new addendum is named **Accommodation Weight Policies**.

## Policy contract

- Value inheritance order: `Global → Property Type → Destination → Property → Unit`. Individual percentages can use **Inherit**. Original native pricing supplies the price; a matching explicit Property Rate cannot be overridden via these shadow weights.
- Scope persistence: dedicated WordPress global option (`mad4b_accommodation_weight_policy_global_v1`), and term/post metadata (`_mad4b_accommodation_weight_policy_v1`). All writes require authorized native admin capabilities, a WordPress nonce, the source's current revision, and a serialized-row compare-and-swap check.
- Global neutral base was saved and verified on both companies: Double is reference; Single, Double, Triple, Standard, Deluxe and Suite all **0.00%**. No ungrounded positive percentages configured.
- Native room name/grade and capacity checks use Relation 223 + original receipt `_mad4b_room_source_receipt_v1` and native Unit fields. A confirmed Suite cannot be priced as Standard; a two-person Unit cannot be shadow-priced as Triple. A nonzero grade weight is rejected if the room grade cannot be proven from original source.
- No inferred room types from `Standered` in the Google Sheet. `Single/Double/Triple` remain occupancy indicators rather than separate Units.
- Original meal board policy `MAD4B_Hotel_Meal_Base_Policy_V1` supplies source recommendation: Aswan hotel BB, Sharm hotel AI, cruise FB. Recommendation does not certify supplier inclusion or apply a second meal charge. Season and rate validity are **not** invented from sheet headings.
- Preview output is always `estimate_only_not_supplier_cost`, `commercial_ready=false`, `rate_written=false`, `checkout_ready=false`, `supplier_contract_approved=false`. It does not call the original Rate writer.

## Tested checkpoints

1. **Source integration:** both sites passed `php -l` for PHP source. JS passed `node --check`. New PHP registered one authorized WP AJAX action, and the native Pricing Controls enqueued JS/CSS with the dependency on the existing Accommodation panel. Both HTTP GETs returned **200** and nonempty asset bytes.
2. **Global persistence:** neutral global policy **saved via the live code** and read back on each site. Baseline Property Rate and Tour Rate post-status counts stayed unchanged.
3. **Real QA Save:** on each site the original **draft QA ONLY** Property `67063` was used for a controlled Save of Single +30% and Deluxe +20%, with Double reference 0%; readback showed values and source `property:67063`. A stale initial revision was rejected with `accommodation_weight_stale_editor_revision`. Preview on `35 USD` returned `54.6000 USD` (1.30 × 1.20). The QA Property was then saved back to all **Inherit**, with effective global weights 0%. No original Rate posts changed. A nonfinancial, all-inherit QA policy record remains for audit.
4. **Native relation identity:** original Obelisk Nile Hotel `36181` uses a different site-local suite ID: Tours.dev `71394`, Wayfatours `71874`. The relation returns `child_object_id` records, not bare IDs; the live code was corrected accordingly.
5. **Room & occupancy safety:** original supplier-backed Suite source is recognized as Suite; choosing Standard returns `accommodation_weight_unit_room_grade_mismatch`. Original Alcazar Superior max occupancy 2 refuses Triple with `accommodation_weight_occupancy_exceeds_native_unit_capacity`. Unclassified room remains explicitly unverified.
6. **Original meal policy:** Aswan/Obelisk `bed-breakfast` from Global; Sharm/Alcazar `all-inclusive` from Destination; Hapi 5 cruise `full-board` from Property Type. Each is advisory, not a supplier contract.
7. **Final acceptance:** `ops/pricing-unified-checkpoints/tests/verify-accommodation-weight-policies-20261121.php` — **48/48 PASS Tours.dev**, **48/48 PASS Wayfatours**, no failures or commercial mutations. The final suite is repeatable and read-only.
8. **Sheet metadata continuity:** the original private quotation intake remains 92 hotel/cruise/coastal groups on both sites; Wayfatours source option was read back after the stale negative-cache issue had cleared, without a source or price write.

## Explicit remaining dependencies — NOT CLOSED

- Logged-in WordPress real-browser visual/interaction Save at mobile and desktop widths was not verified; simulated global `admin_enqueue_scripts` under WP-CLI hit a WooCommerce screen-only hook, so the narrower policy asset hook was tested directly. This CLI artifact is not evidence of a public-site fatal.
- Original spreadsheet prices remain **unapproved quote references**; no automatic or published `property-rates` were created from them. Missing supplier rates/contractual basis and an original room classification still block commercial pricing.
- Need map each room category to the original JetEngine Unit, occupancy, dated supplier cost, and board; prove actual seasons/peak dates, inventory, taxes, children and non-mandatory vs mandatory supplements.
- The original Rate Generator has not been changed to write weighted Property Rates or Tour Rates. A commercial generation path must require an approved rate/receipt, not use this shadow estimate as authority.
- PR #987 remains a separate Draft source branch until normal source reconciliation and review; direct selective runtime deployment is not a PR merge.

**Rollback:** remove only the three newly added accommodation weight files after backing up and verifying active use; preserve all original Rates/Properties/Units. The neutral global option and all-inherit QA policy are inert and can be left intact when disabling the MU component. Do not bulk revert Pricing Lab or supplier-cost code.


## Follow-up: Effective weights and configured policies in Dynamic Pricing Controls

The user's follow-up requested **visibility of actual effective weights and saved/configured pricing policies** rather than only raw numeric editor inputs.

- Added `effective` to the existing authenticated policy AJAX action: for one original Property and optional original Unit, return the six resolved percentages, each winning provenance source, selected reference occupancy, and all relevant scope layers. The response explicitly shows `not_configured` and `stored_inherit_only` instead of implying they are price modifiers. No supplier or live Rate write is called.
- Added `inventory` to the same action: source-backed global policy, WordPress termmeta property-type/destination policies and postmeta Property/Unit policies. Counts distinguish nonzero adjustments, explicitly configured neutral settings, and stored all-Inherit records. Inventory returns an explicit `truncated` flag if it encounters more than the bounded retrieval limit.
- The original admin accommodation panel remains the primary property selector, not a duplicate catalog. The new effective dashboard listens to its original native edit links and auto-syncs selected Property/Unit IDs; Unit policy search and saved policy list derive the true parent Property from original JetEngine Relation 223.
- The policy editor is now a compact collapsed section below the effective matrix and saved policy overview. Saving a policy refreshes the live matrix and configured inventory. Globally scoped policy can be read by authorized pricing staff, but only accounts with `manage_options` may save Global settings; other scopes still enforce the original company and JetEngine post/term permissions.
- Each effective field shows percent, multiplier and the winning Property/Unit/Taxonomy/Global source. QA-only all-Inherit policy records are visible under a separate collapsed audit section, not confused with effective pricing overrides.
- Site data as of release: each site has **1 configured neutral Global rule, 0 nonzero effective adjustments and 1 stored QA-only all-Inherit record**. No supplier amount, season or property rate was made commercially approved.
- Controlled noncommercial QA on each environment: set QA-only draft Property `67063` to Single +25%, Deluxe +10%, read it in `effective` with exact source `property:67063`, saw `configured_adjustments=1` in registry, rejected a stale write, and restored the original all-Inherit policy, verified by independent readback. No Rate or checkout changes.
- **Final automated acceptance 65/65 PASS on Tours.dev and 65/65 PASS on Wayfatours**, plus PHP lint, Node `--check`, live AJAX success, original native panel dependency, both sites serving updated assets over HTTP 200, and identical deployed source hashes.
- Last deployed live SHA256 (both sites): PHP `084355959a2883bd26be9313df69620d6d0d134e0ba11b7a571c918846d2b55a`; JS `8540fbadcc536390c0d5d8b3c7c5996456318e10f3d8c7e02f6c5ec6f2409452`; CSS `18a99dfad690612886506d2b0952f014e98d92d8890a6d22fbde3a50c4b9c284`.
- **Not verified:** the full logged-in browser's native JetEngine Save interaction and visual appearance at 320/390/768/1024/1440. Browser read connector could not read its Pricing Lab tab; no impersonated login or production authorization bypass was used. These are not claimed passed.
- **Not integrated into commercial authoring:** weights remain shadow estimates and do not automatically override published supplier quotes, `property-rates`, `tour-rates` or package checkout. Complete per-hotel Unit/category, date/season and Cost Receipt eligibility before attempting commercial generation.
