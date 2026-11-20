# Wayfatours Saray Pyramids — owner-verified identity and 3 source-backed rooms

**Execution:** 20 November 2026, Wayfatours Production, English property #36316 and WPML Spanish property #71711.

The user explicitly confirmed that the existing former `Saraya Pyramids Hotel` Property represents **Saray Pyramids & Museum View Hotel**. This is an **owner identity approval for editorial updates** and does not equal a verified supplier contract, room rate, room capacity or media license.

## Executed

- Reused **Property #36316**, changed its title and description to the verified official hotel name. Retained original legacy slug `saraya-pyramids-hotel` to preserve existing routing; no duplicate Property created.
- Updated linked **Spanish Property #71711** with Spanish editorial description. Retained existing WPML `trid 343878` and its legacy slug.
- Corrected the documented destination to Giza (English parent Egypt; Spanish Guiza/Egipto), star grade to 4 as described by the hotel, and outdated unrelated contact to official `info@sarayhotels.com`. Preserved Silver / Plata tier. Removed only unwanted English-language term links auto-synced onto the Spanish post by WPML.
- Populated the textual property map field with **El Remaya, 59 Mostafa Ismail, Giza, Egypt**; no coordinates were invented.
- Recorded owner confirmation, pre-change English and Spanish post/meta/term snapshots, source URLs, provenance and commercial prohibition under `_mad4b_property_identity_verified_v3`.
- Inserted and Published three linked **editorial Units**, each with a source receipt and `unit_readiness=pending_review`, `unit_available=false`:
  - #71731 **Standard Room**, official current room details ID 4: https://sarayhotels.com/rooms/saray-pyramids-museum-view/standard-room/4
  - #71733 **Deluxe Room**, official current room details ID 5: https://sarayhotels.com/rooms/saray-pyramids-museum-view/deluxe-room/5
  - #71735 **Family Room**, official current room details ID 6: https://sarayhotels.com/rooms/saray-pyramids-museum-view/family-rooms/6

The official current room index is https://sarayhotels.com/rooms/saray-pyramids-museum-view. It lists 3 categories, while a legacy page https://www.sarayhotels.com/rooms?hotel_id=1 lists 7 partly differently named categories. **Those seven are not seven additional inventory units.** Legacy-only categories remain under source review rather than being generated as potentially overlapping units. Official bed counts (1/2/3) were recorded as beds **not occupancy**. Guest maxima, actual room allocation, prices, physical inventory, meal plans, property/Unit image-license status and checkout remain **unconfirmed**.

## Verification and safety

- Bounded live CLI `verify`: **0 errors**, 3/3 Published Units correctly linked via native JetEngine relation 223, WPML EN/ES terms confirmed, former slug preserved, no media images copied.
- Property Rates **0 Published**; `mad4b_stay_editorial_publish_v1.catalog_activated=false`. Tour-rate generation remains disabled for this Property, and none of the 3 Units is available for commercial sale.
- Prior 18-hotel cohort: 131 → **134 Published EN Units**; all 18 hotels now have at least one linked Unit. The ES **Properties** cohort remains 18/18; Spanish Unit translations are not claimed.
- Pre-change backup and verification receipts stored at `/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/`, not checked into Git.
- Re-runnable code: `scripts/mad4b-stay-saray-owner-identity-and-rooms-cli.php` with fail-closed `plan`, one-time `apply`, `verify`; changes only Wayfatours. DO NOT rerun apply after success.
- Release chain #934 → #935 → #947 → #952 remains Draft until governance approval; production editorial content execution is separate from code release.

## Outstanding

Confirm mapping between current 3 categories and legacy 7 categories with a hotel room taxonomy / supplier sheet. Complete exact official room imagery with allowed use rights and occupancy/capacity/inventory proof. Do not enable room checkout just because the original hotel identity is approved.
