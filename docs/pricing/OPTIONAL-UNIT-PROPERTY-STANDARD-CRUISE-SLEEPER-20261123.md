# Optional Unit / Cabin: native Property Rate Standard baseline

Date: 2026-11-23. Staging Tours.dev and Production Wayfatours. Bounded live implementation, **NOT commercial Package pricing closure**.

## Contract

- A native published **Unit cost Rate** wins when an explicit validated Unit was selected. Without a selected Unit, resolve only a **property-scoped** (`prop_related_unit_id = 0`) published native **Property cost Rate**. Never attach an invented Unit or treat a Unit Rate as a generic fallback.
- Preserve original JetEngine Properties, Units, Property Rates and native relation 223. Optional Unit / Cabin assignment must not prevent a read-only cost-source preview. If an explicit Unit is selected its original relation, receipt, publication and capacity must still be checked.
- Source status always remains preview-only. Full date window, currency, supplier cost-vs-retail distinction, cost basis, QA flags, Stop Sale and unique price-source selection remain required. A price-source preview is never an independently signed supplier Cost Receipt and never authorizes WooCommerce checkout or publishing a Tour Rate.
- For a **cruise stage**, a signed operator Cost Receipt can refer to **vessel Property + sailing program + service date**, with `cabin_unit_id=0`; an explicit Cabin Unit, when present, must still match. Cruise basis must be one of `per_cabin_night` or `per_person_night`; never equate a 3-night program, 4-night reverse program and 7-night program. Meals for cruises default to FB by separate existing policy, respecting any explicit supplier inclusion.
- For a **sleeper stage**, a registered native train ID, passenger boarding/alighting segment, dated train, and Standard/Elite class govern the source; no accommodation Unit CPT is required. Standard class is the missing-selection default. Existing train 87 **Luxor** intermediate boarding verification must not regress to whole-route Aswan board time. Permitted bases: `per_cabin_trip` or `per_person_trip` (trip once, not nightly accommodation).
- Company **Self** remains fallback supplier when no explicit external supplier is assigned, but it still requires real supplier/internal documented cost, valid availability and trusted signed approval. Never convert supplier retail or sheet guesses into cost silently.

## Actual deployment

Exact-preimage 3-file installation on both sites via `/opt/mad4b-company-provisioning/bin/apply-optional-property-standard-20261123.py`:

1. `wp-content/mu-plugins/mad4b-accommodation-weight-policies-v1.php` — native Standard price source resolution with no Unit ID.
2. `wp-content/mu-plugins/mad4b-package-cost-receipt-gate-v1.php` — receipt identity permits no Unit/Cabin; preserves latest live train 87 segment and signed-authority checks.
3. `wp-content/mu-plugins/mad4b-package-supplier-admission-v1.php` — native admission no longer treats missing Unit/Cabin as missing service identity; preserves live company Self supplier and sleeper train segment mapping.

Rollback snapshots and SHA manifests: `/opt/mad4b-company-provisioning/reports/pricing-optional-unit-20261123/{source,wayfa}-before/` and `{source,wayfa}-receipt.json`. No price, catalog, reservation or order data was mutated. The original Package Stage Guard has deliberately **not** been bypassed.

## Acceptance and caveats

- Hermetic original Property Rate source selector: **23/23 PASS**.
- Hermetic signed receipt identity with no Unit, cruise program mismatch, wrong sailing and train 87 Luxor segment: **11/11 PASS**.
- Three real PHP files lint clean in both live PHP containers. WordPress class load smoke test passed on both sites.
- Latest live published Property Rate count by native WordPress: **Tours.dev 56; Wayfatours 0**. Publication alone does not imply cost supplier approval, validated basis or Stop Sale release. This change does not publish any Rates.
- **Unresolved commercial connector:** original `MAD4B_Package_Stage_Rate_Guard_V1` and original Rate Planner still block cruise/sleeper Stage Rate authoring, and can still demand a Hotel Unit. No approved stage Cost Receipt aggregation into `approved_price` has been added. The final step must validate itinerary, direction/program, selected Property, date-specific cost basis, party, meal/occupancy, unique `stage_key`, approved supplier evidence, and idempotent inclusion exactly **once**, and continue separate pricing/tax/FX/publish approval.

## Follow-up source-implementation boundary

Patch and test the *current deployed* Package Stage Guard and original Rate Planner through an exact-preimage selective merge. Do not replace their newer itinerary-overnight reconciliation, destination/tier/season handling, sleeper station source, company vendor self policy or latest transport route assignment. Require staging simulator plus no-double-charge regression for `per_person_night`, `per_cabin_night`, `per_person_trip` and `per_cabin_trip`; never multiply train trip by nights or cruise nightly base by two different component identities. Keep commercial generation/checkout disabled until independently approved supplier costs and complete E2E proof exist.
