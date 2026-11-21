# Tour Rate creation UX v2 — 2026-11-21

## User intent and architecture boundary

Prioritize actual **new Rate creation**, not just decorative tabs. Do not add a Package Components tab for every Tour. Preserve the canonical scalar `tour-rates.related_tour_id` association, and preserve the existing parent Tour's Query 313 `tour_queried_rates` field as **inventory only**. Never add a duplicate JetEngine Relation or mirror child Rate IDs on the Tour.

## Applied creation-first native JetEngine tab order

The `tour-rates` CPT contains 52 native data fields and six native `object_type=tab` sections (58 total JetEngine entries). Rearranged the same original business fields, retaining the 6 existing tab keys and their IDs.

| Order | Native tab label | Original data fields | Operator purpose |
| --- | --- | ---: | --- |
| 1 | Tour & Dates | 11 | Required Tour selection; Rate validity; itinerary/program and optional departure metadata |
| 2 | Traveler Prices | 17 | Selling currency and price basis first, then Egyptian price and sale toggles **before** conditional Single/Double/Triple values |
| 3 | Accommodation | 5 | Whether Property applies, pricing assignment, single Property or multi-stage hotel/cruise repeater |
| 4 | Transportation | 7 | Whether transport applies, assignment/selection mode, price policy, native stage or alternative and markup override |
| 5 | Availability & Stock | 4 | Availability and allotment control **before** inventory quantities/sold values |
| 6 | Policies & Notes | 8 | Financial policy overrides and notes, kept after core creation inputs |

The native `show_rate_departure_details` field moved from Transportation to Tour & Dates. Conditional controls were moved immediately ahead of the native fields they control. The `related_properties_id` and `_booking_experience_stay_stages_v1` controls remain mutually conditioned by native JetEngine logic rather than being cloned. `related_tour_id` is still the unique authoritative Tour link, with its original query options and required flag intact.

Selected editor labels and descriptions are more task-oriented (e.g., `Tour · select first`, `Rate valid from/until`, `Selling currency`, `Price basis / unit`, `Include Property / accommodation?`, `Transportation selection method`). These are **UI text changes only**. No pricing values, field names, IDs, types, default values, required flags, condition arrays, glossary/query sources, nested repeaters, REST settings, or taxonomy were changed.

## Live site verification

**Tours.dev:** applied native JetEngine schema v2, resulting serialized SHA256 `b47966ae4b718fedd8020cb931cf00f95da4a87dce58ac0b55fdc6e92b76c692`.

**Wayfatours Production:** same display/layout v2 applied, resulting serialized SHA256 `54c560774742436bad4abb6d8d479aeb2203257555a5d99c0de343d79a1b8d53`.

- Each site's preflight passed read-only. Only `wp_jet_post_types.meta_fields` for CPT `tour-rates` was updated through a compare-and-swap SQL guarded against concurrent schema changes.
- `wp eval-file` PHP lint PASS both sites; apply PASS; immediate readback and second invocation both `already_applied=true` with zero writes.
- Compared all 52 data-field definitions against exact pre-v2 schema snapshot after stripping **only** display labels and descriptions: **0 behavioral differences**.
- Every native `conditions[].field` still points to an existing field: **0 unresolved references**.
- Existing Tour Rate post counts unchanged: 225 Tours.dev and 106 Wayfatours. Existing Query 313 `tour_queried_rates` unchanged; 0 JetEngine `tours-and-activities → tour-rates` Relations created.
- Native `_booking_experience_stay_stages_v1` / `_mad4b_transport_stages_v1` repeater field definitions untouched.
- Backups saved root-only (0600):
  - `/opt/mad4b-company-provisioning/reports/tour-rate-native-tabs-20261121/toursdev-v2-before-47002b76bc32d5ee.ser`
  - `/opt/mad4b-company-provisioning/reports/tour-rate-native-tabs-20261121/wayfatours-v2-before-86c94f69dbf6e927.ser`
- Idempotent source / CAS migration: `ops/pricing-unified-checkpoints/tools/tour-rate-native-creation-order-v2.php`.

## Operator workflow and remaining acceptance

Create a Rate:
1. **Tour & Dates:** choose the original Tour (required), verify validity dates, choose Program if applicable.
2. **Traveler Prices:** choose selling currency and unit, then opt into Egyptian/sale prices if applicable; fill prices by occupancy.
3. **Accommodation / Transportation:** set the explicit Yes/No selection and configure the original native component fields only if applicable; do not create a mirrored Tour component field.
4. **Availability & Stock:** set explicit availability and inventory status; stock quantities only when managed.
5. **Policies & Notes:** override inherited policies only where necessary, then save using the original WordPress/JetEngine Save.

Important: The native schema still marks several Yes/No controls as required, without new default values. These must be explicitly reviewed when creating a Rate. No auto-approvals, default "yes", blanket hidden tabs, or guessed supplier choices were added.

**Limitation:** logged-in WP admin visual and browser Save/Repeater E2E were not run; this release is verified at JetEngine native schema level and WP-CLI readback, not presented as a complete interactive acceptance. Source PR #987 remains Draft/unmerged; the site schema update itself is already live on both sites. No Rates or checkout/payment data were changed.
