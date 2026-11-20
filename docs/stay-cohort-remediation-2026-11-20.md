# Wayfatours — 18-hotel Gold/Silver/Diamond continuation (20 Nov 2026)

This checkpoint records **executed, bounded Production changes** and separate unresolved source-verification tasks. It does not certify room rates, stock allotment, public hotel catalog pages or checkout.

## Executed and verified on Wayfatours Production

- Original English Properties: **18/18 Published**, with source tier grouping Silver 5 / Gold 6 / Diamond 7. Existing 131 linked English Units stay Published.
- WordPress Property #36477 title corrected from `Hiton Ramsis` to official `Ramses Hilton`. The legacy `hiton-ramsis` slug remains unchanged to preserve existing routing. Previous title and rollback preview were recorded.
- WPML: **18/18 linked Spanish Property posts Published**, IDs **71709–71726**, each with genuine Spanish editorial summary and source `trid` connection. Spanish `package_levels`: **Plata 5 / Oro 6 / Diamante 7**. Native media attachments referenced where available, not duplicated. No Spanish Unit catalog was claimed.
- The exact-site helper `scripts/mad4b-stay-wayfatours-es18-editorial-cli.php` has guarded modes `plan`, `stage`, `publish`, `verify`. Completed live `verify` returned **18 Published / 0 violations**. The first staged canary caught a WPML language-sensitive taxonomy mismatch, safely deleted its own partial Draft and switched to assigning the Spanish term in ES context; no pre-existing records were deleted.
- Official Fairmont Nile City accommodation source documents **maximum 2 persons** for five recorded categories. Populated `unit_max_occupancy=2` for exact Units **70890, 70891, 70892, 70893, 70894**; evidence in `_mad4b_room_official_occupancy_v1`, previous field value empty. Linked English Unit count remains 131. Room-capacity coverage moved **51 → 56**, with **75** still requiring source-backed occupancy.
- Fairmont / Hilton photo review saved under `_mad4b_room_photo_match_review_20261120`: Unit **70893** keeps shared Attachment **71364** (official image is reproduced across two categories); Unit **70905** keeps partner Attachment **71413**. Neither can be asserted an exact-room visual match. No existing images were replaced.
- Property **36316 Saraya Pyramids Hotel** has a documented candidate identity `Saray Pyramids & Museum View Hotel` and the official candidate's seven listed room-category names. The existing source explicitly says these two records are **not proven identical**. Stored the candidate as non-authorizing; **0 Units created** and no identity reclassification.

## Immutable commercialization boundary

After translation publication, direct HTTP checks were: `/` **200**, `/properties/` **404**, `/es/properties/` **404**, `/units/` **404**, and one direct translated Property URL **404**. These checks do not establish that every possible API/detail route is blocked.

Property Rates: **28 Draft / 0 Published**. `catalog_activated=false`; no change to rates, inventory, WooCommerce stock, checkout or payment. Quarantined bad-import Property **36260** and Units **37553–37555** remain Draft, with 14 QA Units excluded.

## Source evidence and unresolved blockers

- Saray candidate official rooms: https://www.sarayhotels.com/rooms — supplier/legal identity must match Property #36316 before any room creation.
- Fairmont official categories, 33 m² and 2 persons maximum: https://all.accor.com/hotel/A5E9/index.en.shtml
- Fairmont official image bytes `a5e9_rokgb_00_p_1024x768.jpg` and `a5e9_rokga_00_p_1024x768.jpg` share SHA-256 `1b3e66a0425e03a0409ae3f8c040dbd3eff9261806a0412255e299d1f22b7b65`. Other gallery shots can differ, but do not prove a distinct bedroom category.
- Hilton room-category factsheet: https://www.conferencehotelgroup.com/img/hotels/files/6809/updated%20Hilton%20Cairo%20Heliopolis%20Factsheet.pdf — no exact photo verification.
- 75 missing capacities, Saraya identity, and exact photo matches remain blocked. The Spanish summaries do not claim that Unit translations, catalog sale, or commercial approval are complete.
- Execution logs and preflight/rollback receipts are held on the authorized VPS under `/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/`; not committed with sensitive operational logs.

## Release governance

Stacked PR sequence: **#934 → #935 → #947 → #952**, all Draft when inspected. Current-head CI for the earlier #952 head succeeded **33/33** prior to this new source commit. Re-run CI and complete upstream merge/release approval in dependency order; do not equate successful CI with production payment/catalog permission.
