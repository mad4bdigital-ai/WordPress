# Tour Rates — native JetEngine tabs; one Tour link, no duplicate Relation

Date: 2026-11-21. Sites: `https://tours.dev.mad4b.com` and `https://wayfatours.com`.

## Native schema result: APPLIED to both WordPress databases

The built-in `tour-rates` JetEngine CPT initially contained **53 meta-field definitions**, including only one `object_type=tab` at `traveler_rates`. Added **five** visual-only native JetEngine `tab` separators with stable unique IDs; renamed the legacy `traveler_rates` tab title for clarity. JetEngine CPT row now has 58 entries: **52 unchanged data fields + 6 native visual tabs**. The original 52 data-field definitions match exactly, including every key, repeater, allowed value, field condition and lookup. No commerce data changed.

| Native Tab | First native field | Description |
| --- | --- | --- |
| Tour & Schedule | `related_tour_id` | Tour identity, date validity, itinerary/program/destination |
| Property, Currency & Stock | `has_properties` | Source Property, currency, nationality, Rate allotment |
| Traveler Prices & Availability | `traveler_rates` existing tab | Availability, single/double/triple and sale prices |
| Financial Policies | `shared_financial_tax_price_basis` | Tax basis, fee, discount, refunds |
| Accommodation | `accommodation_assignment_mode` | Native hotel/cruise stages and alternative Properties |
| Transportation | `has_transportations` | Native vehicle/route/stage selection and price policy |

The tabs are **actual JetEngine `object_type=tab` data**, not a separate web editor, cloned metabox, javascript overlay or second Save. Their order mirrors the original native field order; field conditions were not rewritten.

Source for idempotent, compare-and-swap updater: `ops/pricing-unified-checkpoints/tools/tour-rate-native-jetengine-tabs.php`. Running it without `MAD4B_RATE_TABS_APPLY=1` is read-only preview. Updating it again returns `already_applied=true`, without writing.

### Verified on both sites

- Tours.dev: schema `53 -> 58`; new SHA256 `47002b76bc32d5ee3ae51bd7027c584425ee80fa67e0f1d55621a05a6b48c511`.
- Wayfatours: schema `53 -> 58`; new SHA256 `86c94f69dbf6e927e6e2c5da0737afc97621f8518017a4419352e1f652081d63`.
- All **original 52 data fields** compare equal byte/structure-wise against original schema backups, except the display title of the original visual `traveler_rates` tab.
- Existing Rate post counts unaffected: **225 source, 106 Wayfatours**.
- `tour_queried_rates` on Tour remains its existing JetEngine Query 313 `checkbox` source used for inventory; no extra Tour view was created.
- No JetEngine `tours-and-activities -> tour-rates` Relation exists or was created.

Original JetEngine schema backups (root-only, mode 0600):
- `/opt/mad4b-company-provisioning/reports/tour-rate-native-tabs-20261121/toursdev-original-meta_fields-43ac9853eb31d36a.ser`
- `/opt/mad4b-company-provisioning/reports/tour-rate-native-tabs-20261121/wayfatours-original-meta_fields-d01b5440b62f3a83.ser`

## Relationship contract — do not duplicate

- **The only authoritative Tour Rate -> Tour identity is the native scalar `related_tour_id` postmeta in `tour-rates`.** It is an already-required JetEngine `posts` field; currently it queries Tours for the native Rate selector.
- **Tour `tour_queried_rates` remains the old Query 313 inventory UI**; never use the checked/inventory values as authoritative pricing data and never persist mirrored Rate IDs on the parent Tour.
- All 106 Wayfatours and 225 Tours.dev active/draft Rates were checked: one canonical scalar Tour ID each, no missing, multiple or orphan links in these populations.
- The Package Supplier Admission source inspector previously read `_mad4b_transport_stages_v1` off the wrong post type (Tour). This is corrected in PR #987 source only: it derives linked Rates from `related_tour_id`, then reads the native JetEngine `_mad4b_transport_stages_v1` repeater from each Rate, without creating a relation or a new Rate inventory. Standalone tests **28/28 PASS**; read-only source candidate on Wayfatours resolved **32 Rates for 8 Package Tours**, **0 native transport stage rows**.

**Release distinction:** JetEngine visual tab schema updates ARE applied live on both sites. The Package Supplier Admission PHP/JS corrections remain **source-only in Draft PR #987** and have **not been deployed** to the MU plugin runtime. CI/release verification and an actual authenticated WP admin browser visual/save test remain separate.

## Conditional Package component UI (design only, not added)

Do **not** create a global, always-visible `Package Components` tab inside every Tour. If a contextual guide is useful:
- Show for a Tour whose canonical `tour-types` taxonomy has `packages`; all eight Wayfatours Package examples have this term even though `tour_mode=tour`.
- Day/Half-day Tours should not show an accommodation/cruise segment by default.
- Standalone Nile Cruise Tours may show Program/Sailing context when `tour_mode=cruise_tour`, separately from the Package-only scope.
- Show the cruise subsection only when the actual source Program/itinerary contains a cruise stage. Do not infer it from words in a title.
- Reuse existing Tour itinerary and Guide Days Bulk Review; link to the original Rate/Property/transport editor as applicable.
- Do not add a second Rate inventory: Tour `tour_queried_rates` is already present. Do not clone accommodation, transportation, guide, selling-price or supplier fields onto Tour.
- A contextual panel, if later approved, is navigation/readiness only; native JetEngine fields stay with their current owners.

No Rates were published, no cost authorization or checkout activation performed.
