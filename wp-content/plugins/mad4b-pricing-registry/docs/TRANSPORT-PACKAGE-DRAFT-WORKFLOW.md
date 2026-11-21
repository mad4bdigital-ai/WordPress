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
