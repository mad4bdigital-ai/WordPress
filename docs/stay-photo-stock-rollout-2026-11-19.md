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

## Second wave — official Triumph room galleries
- Published official Triumph Plaza and Triumph Luxury room pages provide exact matches
  for 8 Plaza and 10 Luxury existing Unit labels. Each official room-page heading
  and live JetEngine Property/Unit relation were checked before admission.
- 18 additional unique 1920 × 1080 images were attached as Featured Images to
  18 more **Draft** Units. No rate/price/stock or Unit post status was changed.
- The same official room pages supplied 35 more unique photos, and the original
  `unit_gellery` JetEngine Gallery field now has three images on 17 of those Units,
  and two on the single room where the page contained two images.
- Live WordPress audit after both waves: **93 curated draft Units**, **94 Unit
  featured images**, **95 Unit galleries**, **18 enriched multi-image galleries**, and
  **39 editorial + 14 QA** Units still without images (53 total).
- All 18 original Featured Images are unchanged, all gallery additions retain
  source/room-page/sha/provenance, and reversible gallery-only rollback was
  confirmed in preview without executing any rollback.
- Only publicly accessible official photos were used on the basis of the user's
  attestation, not a legal conclusion that they are copyright-free.

## Third wave — official Four Seasons First Residence single-room photos
- 12 Draft Units (IDs 70878–70889) were matched to twelve room/suite names
  at Four Seasons Cairo at The First Residence. Hotel Property 70734 remains
  **Draft**, so photo curation explicitly allows a Draft Property only for
  editorial image enrichment, never for commercial readiness.
- Original room-page URLs use the official Four Seasons website; image bytes
  were obtained from the hotel's official press image domain. Source rights
  remain independently **unverified**, with the user's use approval recorded.
- 12 unique JPEG photos were imported into the existing Unit Featured Image
  and JetEngine `unit_gellery` field, leaving all twelve Units Draft.
- Direct production audit after wave three: **105 curated Draft Units**,
  **106 Featured Images**, **107 Unit Galleries** across the 148-unit catalog.
  Remaining **27 editorial + 14 QA** units without photos, **41 total**.
- Zero broken curated files and zero missing image-source provenance entries.

## Stock policy
- `mad4b_stay_inventory_sales_policy_v1` is tenant-bound to each website and applies only to accommodation.
- Mode: `unmanaged`. Physical allotment/stock quantity is **not** an editorial readiness prerequisite. Maximum guest occupancy, Stop Sale, date eligibility and supplier/availability confirmation remain separate checks.
- WooCommerce global `woocommerce_manage_stock` remained `yes` on Wayfatours, unchanged. Other services remain unaffected.
- Existing accommodation quote preview already runs `availability_mode=price_only`. No active accommodation checkout exists, so the policy does **not** claim that a future booking/payment flow has been integrated or certified.

## Remaining image coverage by Property (production)
- 27 non-QA Units require photos: Tolip Gardens (4); Sheraton Cairo (3),
  Fairmont Nile City (3), Hilton Golf 6 October (3), Sofitel Downtown Nile (3),
  Sofitel El Gezirah (2), Hilton Grand Nile Tower (2), Ramses Hilton (2),
  Barceló Cairo Pyramids (2), Hilton Heliopolis (1), Sonesta (1), and one
  Unit linked to a Property with invalid `#VALUE!` name.
- Ten of those missing Units already have legacy source references that were
  excluded due to duplicate content, source quality or type: reusing photos
  across different room types requires genuine photo-to-room corroboration.
- Published Unit images and Unit QA records were not modified by this run.

## Commercial blocks
Real company-approved selling prices, child/occupancy capacity, verified room variants, Rate validity, the financial ledger and booking authorization remain separate, unresolved work. Draft/QA rates cannot be published automatically.
