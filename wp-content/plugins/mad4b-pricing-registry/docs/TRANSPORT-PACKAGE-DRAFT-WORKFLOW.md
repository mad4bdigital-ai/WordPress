# Native Transportation → Tour Rate Draft Workflow (2026-11-21)

This change selectively reconciles working Tours.dev and Wayfatours patches with the WordPress source. It does **not** bump, certify, or install a numbered release.

## Existing fields (no duplicated Transportation inventory)

- `transport_selection_mode`: simple OR vs stages.
- `related_transportations_id`: source IDs for a simple draft.
- `_mad4b_transport_stages_v1`: stage keys, days, origin/destination, OR/AND, price policy and selected source IDs.
- `transport_price_policy`: inherited price-policy mode.
- `_mad4b_transport_component_cost_inputs_v1`: *only* per-selected-journey vehicle, operating inputs and stage references needed for cost modeling. It is not a duplicate price or Rate store.

Changes are written only to an **existing Draft Tour Rate** by an authorized user, after route, identity and WPML checks. The complete saved-fields digest is required on each write; a second tab with a stale hash is rejected. Writes have readback and restorative rollback on mismatch.

## Draft eligibility vs commercial readiness

- Existing route matching and Tour Component status are reused (no second matching engine).
- A published, explicitly opted-in SELF PDF operation with a city-matched route and positive vehicle reference cost may be **draft eligible** even when `trans_available_status` is empty.
- This does not infer supplier inventory, actual pickup/dropoff, travel-date validity, taxes, a sellable Rate, or checkout authority.
- Review-needed and excluded sources remain separately visible with reason codes.
- Only explicitly selected OR alternatives are costed; AND components require distinct selection. Duplicate stages are rejected.
- Cross-language selection requires a matching source in the Tour Rate's WPML language.

## Source destination coverage

The verified PDF route-evidence register contains 22 target slugs. At this
checkpoint, 19 have matching native `destinations` taxonomy terms; Ismailia,
Safaga, and Dendera are unresolved. They are reported as review blockers,
**not** silently created or mapped to another place. A blank destination is
allowed in the planner for local or unspecified routes, but these results
remain in the route-review group rather than being treated as a verified
point-to-point draft component.

## Cost presentation

- Original PDF SELF company-cost reference (EGP) is presented separately from the calibrated distance/time operating estimate.
- The PDF reference already incorporates the configured regional 5% discount where applicable, *once*.
- Modeled markup output is an unapproved scenario. No cost or price is silently copied into a Transport Rate or WooCommerce.
- The reference is not a confirmed supplier price for an arbitrary future travel date.

## Rate Generator

The existing `mad4b-rate-generation` interface remains the only complete Tour Rate draft generator. It requires a whole-package price including hotel, guiding, transportation, occupancy, extras and currency. The transport estimate alone must **never** become the complete Tour Rate selling price. The editor links to this generator; once a separate Draft Rate exists, native transportation assignments can be saved into it without creating a separate Transport Rate.

No commercial Rate is published or approved automatically.

## Tests and restoration

1. Run `wp --user=<authorized-admin-id> eval-file wp-content/plugins/mad4b-pricing-registry/tests/transport-package-workflow-regression.php` inside the site's WordPress root.
2. Confirm 19/19 read-only checks, and API/admin panel rendering.
3. Reversible manual staging test: snapshot a Draft Tour Rate's five scoped metadata fields, save a one-stage source/cost input, validate readback, attempt a stale hash, restore exact original metadata, validate original hash.
4. Keep `trans_available_status` untouched. Never use arbitrary PDF city centroids as proof of actual road distance.
5. Prior live backups and exact SHA-256 before/after manifests reside in `/opt/mad4b-company-transport/package-workflow-20261121/` on the authorized VPS.

## Open commercial dependencies

- Real Tour Rates still require editor assignments; this patch does not guess itinerary stages for existing tours.
- Supplier dates, capacity, exact pickup/dropoff and verified vehicle road distance must be confirmed.
- Accommodation, guiding, extras, child pricing, occupancy, FX/tax and final Quote/Deposit/notifications require independent full-package readiness.
- A logged-in browser walkthrough remains needed for visual interaction, especially mobile and the JetEngine repeater saving context.

Do not overwrite site-specific bootstrap differences on Wayfatours with the Tours.dev bootstrap file without review.

## 21 November 2026 — cross-site package stage checkpoint

The authorized source-first live operation reconciled all eight canonical English
package Tours on Tours.dev, with 28 stage geometries (19 hotel/resort, seven
cruise, two sleeper train), after comparing the underlying itinerary/includes
digests with Wayfatours. At the outset, Tours.dev lacked all eight active stage
plans. Five identical-source plans were imported as stage-only, unsellable
proposals. Three editorial variances were checked in full; two textual changes
were kept without replacing source content. The third, Tour 60441, had an exact
duplicate Day 5 and an exact duplicate Cairo hotel included item. Both exact
duplicates were removed from Tours.dev with before-images and digest readback,
yielding the reference 10-day geometry.

All eight source stage plans explicitly contain **zero selected hotels, zero
selected Units, zero approved supplier cost receipts, and no commercial
authorization**. Structural reconciliation does not copy WordPress post IDs
across sites: Wayfatours Property ID 70732 identifies a Hotel but Tours.dev
ID 70732 is Transportation. Source/target identity must always be checked.

Five source Cairo Hotels with verified identical titles, published hotel CPTs,
Cairo destination, and no pre-existing package tier were classified:
Silver = Tolip Gardens (36197), Triumph Plaza (36209);
Gold = Triumph Luxury (36140), Hilton Grand Nile Tower (36479);
Diamond = Sofitel Cairo Downtown Nile (36355).
The 16 Cairo stay stages across eight packages now have those as **OR
alternatives only**, without selected room, contract cost, supplier capacity
or booking permission. Giza-only and mismatched-title/cross-ID hotels were not
promoted. Platinum was not assigned. Luxor, Aswan and Sharm have real Property
catalogs but no approved package-tier, Unit and cost chain yet.

The Package & Rates UI now has a separate, user-triggered read-only multi-tier
readiness board, based directly on the native Package Stage Guard, for a
user-selected date window. It reports the eight source Tours, 28 components,
24 (8 × 3) tier reviews, 28 unpriced stage receipts, and all blockers. Both
sites returned 16/16 module regression checks, 19/19 pre-existing transport
workflow regression checks, successful protected AJAX, checkout=false, and
no price writes. Both live homepages and scripts returned HTTP 200.

Both sites' Property Rates are still Draft only (29 on Tours.dev and 28 on
Wayfatours in the live audit), with the visible records being QA or synthetic
fixtures, not published, dated, verified SELF supplier costs. Zero of 24
package-tier reviews is commercially price-ready. No generator, WooCommerce,
Quote/Deposit, passenger price, tax/FX or supplier availability is approved by
this work.

All before-images, audited inputs, staged code and check-point manifests are
held under the authorized VPS path
\`/opt/mad4b-package-checkpoints/2026-11-21-stage-components/\`.
Do not blindly copy plans, terms, IDs or rates to another company. This PR
contains source code, not the site-specific live postmeta migrations.

### Follow-up: Draft Room Category Staging — 21 November 2026

Safely reconciled **35 officially sourced, non-bookable Room Category Drafts**
into Tours.dev for four ID-verified Cairo Properties:
Tolip Gardens (4), Triumph Plaza (8), Triumph Luxury (10), and
Sofitel Cairo Downtown Nile (13). All target Unit IDs were newly generated,
not copied from Wayfatours. Created 35 native JetEngine Property→Unit
relationships (rel_id=223) and verified source receipts, identity fingerprints,
stage statuses and WPML English assignment. None are published or available.
No rates, inventory, occupancy, media, or checkout approvals were copied.

Two Hilton Grand Nile Tower room labels were **excluded** because their
source was a gallery/image label with category_review_required rather than
an approved official room category page. The other eight nonmatching
Wayfatours-only Cairo hotel identities were not recreated by guessing IDs.

The 35 Draft unit category IDs are now listed in Cairo stay stage
room_candidate_refs[package_level].unit_ids_en for the eight canonical
English source package Tours (16 Cairo stage rows). No actual
selected_property_id or bookable_unit_ids was set. Each stage
offers 12 Silver / 10 Gold / 13 Diamond unbookable candidate references;
Gold includes Hilton as Property alternative without Units until confirmed.
These are references to 35 unique category records, not 560 distinct
rooms or saleable inventory.

The live Package & Rates readiness board now reports unbookable Draft
Room Category counts per stage and tier, separately from eligible
Properties and commercial price readiness. New read-only regression
checks: **18/18 PASS per site**, all prior Transport checks **19/19 PASS**.
It still finds **zero price-ready combinations** because provider contracts,
dated occupancy / inventory and Approved Supplier Cost Receipts are missing.

Detailed source audits, full before-images, cross-site mapping and
rollback records are on the authorized VPS under
/opt/mad4b-package-checkpoints/2026-11-21-stage-components/.
Do not include these site-specific postmeta records in a source code
release or assume the same IDs on new sites.
