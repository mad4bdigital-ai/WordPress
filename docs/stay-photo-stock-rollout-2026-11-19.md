# Accommodation photo coverage and unmanaged stock — 19 November 2026

## Scope
Wayfatours Production, with the same accommodation-only inventory policy deployed to Tours.dev Staging. No WooCommerce global stock changes, no new pricing CPT, no published rates, no checkout activation.

## Unit photos
- Existing WordPress `units` CPT: 148 (3 published, 145 draft at this inspection).
- Before: 1 Unit with featured image, 2 Units with a nonempty native JetEngine gallery.
- 85 official-hotel references were already stored in `_mad4b_unit_official_room_photo_reference_v1`. The user authorized use of these publicly accessible references.
- Preflight excluded 1 SVG logo, 8 repeated URLs, and 1 repeated image-content hash. 75 distinct image files were validated with MIME, dimensions, source-domain allowlisting, canonical Unit/Property relation and SHA-256 checks.
- 75 **draft** Units received an image in the original `_thumbnail_id` and `unit_gellery` fields. All new image attachments retain official source URL, source page, checksum, original rights flag and an explicit owner-attested use record.
- These rights assertions are *not* independent copyright/license verification or public-domain claims. Original `external_source_reference_only_not_licensed_for_copy` metadata remains intact; public accessibility alone does not determine the license.
- After: 76 Units with featured images, 77 with native gallery; no change to published Unit statuses or prices. **71 Units still lack images**, of which 14 are QA/Test and 57 are editorial Units requiring additional genuine room photos.
- Concurrent-write remnants: 2 duplicated, unreferenced attachments (71008, 71029) were identified and safely deleted after verifying source SHA and lack of featured/gallery references; valid attachments (71010, 71031) remain.
- Rollback is available only through explicit Unit IDs and separate owner confirmation; it refuses any gallery or thumbnail changed since import.
- Source photo files and the sensitive operational manifest intentionally are not committed into Git. The site-local WordPress Media Library is the actual media store.

## Stock policy
- `mad4b_stay_inventory_sales_policy_v1` is tenant-bound to each website and applies only to accommodation.
- Mode: `unmanaged`. Physical allotment/stock quantity is **not** an editorial readiness prerequisite. Maximum guest occupancy, Stop Sale, date eligibility and supplier/availability confirmation remain separate checks.
- WooCommerce global `woocommerce_manage_stock` remained `yes` on Wayfatours, unchanged. Other services remain unaffected.
- Existing accommodation quote preview already runs `availability_mode=price_only`. No active accommodation checkout exists, so the policy does **not** claim that a future booking/payment flow has been integrated or certified.

## Remaining image coverage by Property (production)
- 57 non-QA Units still lack suitable room photography. Highest remaining counts:
  Four Seasons First Residence (12), Triumph Luxury Hotel (10), Triumph Plaza Hotel (8),
  Tolip Gardens (4), Sheraton Cairo (3), Fairmont Nile City (3),
  Hilton Golf 6 October (3), Sofitel Downtown Nile (3).
- Others: Sofitel El Gezirah (2), Hilton Grand Nile Tower (2), Ramses Hilton records (2),
  Barceló Cairo Pyramids (2), Hilton Cairo Heliopolis (1), Sonesta (1),
  and one data-quality Property title.
- Duplicate references for ten Units must not be reused across different room types
  without genuine photographic corroboration.
- Published Unit images and Unit QA records were not modified by this run.

## Commercial blocks
Real company-approved selling prices, child/occupancy capacity, verified room variants, Rate validity, the financial ledger and booking authorization remain separate, unresolved work. Draft/QA rates cannot be published automatically.
