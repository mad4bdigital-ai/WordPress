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

## Fourth wave — 22 additional Units on Wayfatours Production
- Tolip Gardens: four category-specific room photographs from the hotel's
  official accommodation page. Three meet the 800-pixel minimum; Chalet is
  only 699x466, marked for quality review and never upscaled.
- Fairmont Nile City / Sofitel El Gezirah / Sofitel Downtown Nile: six
  different official Accor room photographs, source-reviewed v2 and checked
  against exact room detail headings. Original invalid or duplicate v1
  references are preserved.
- Sheraton Cairo: three Marriott suite images with exact gallery captions,
  replacing the broken Presidential-Suite SVG in a v2 record, while retaining
  that original source record without alteration.
- Hilton Pyramids Golf: three original hotel media assets matched to the
  Unit category and existing Property relation.
- Ramses Hilton and Hilton Cairo Grand Nile: four official gallery images;
  Ramses hotel's existing title typo remains an editorial correction and
  Twin Nile gallery photo is flagged for room-variant confirmation.
- Barceló Cairo Pyramids: two category-specific Scene7 photos sourced from
  the hotel's room-code SUP and DLX image components, never third-party sites.
- No changes to Unit prices, inventory or post statuses. Every imported
  attachment preserves exact source URL/page, SHA-256, user-attested use, and
  the fact that image rights were not independently verified.

## Fourth-wave inventory snapshot (before final triage)
- 148 Units total; 127 have source-curated photos. Existing earlier media
  brings totals to 128 Featured Images and 129 native Unit galleries.
- 5 non-QA Units remain without imagery:
  - 70905 Hilton Cairo Heliopolis / Guest Room Pool View: publicly surfaced
    gallery link currently 404; usable original asset not yet verified
  - 70893 Fairmont Nile City / Deluxe Premier Partial Nile View: hotel's
    listing uses an image shared with another category; no unique asset yet
  - 70873 Sonesta Tower Executive King/Twin: legacy image duplicates another
    Unit's SHA; need different, verified room photo
  - 70820 Sofitel Downtown Executive King Club Millesime: official detail
    uses same hero image as Deluxe King; unique image still needed
  - 37555 Double Room With Town View: Property 36260 has published title
    #VALUE! and description describing Hotel Stanford in New York.
    Identity is not verified as an Egyptian hotel. Do not attach arbitrary
    Egyptian hotel photography or allow commercial activation.
- 14 QA/Test Units intentionally excluded.
- Zero broken curated source files or missing provenance; all 127 newly
  curated Units remain Draft. No real prices or checkout were activated.

## Fifth wave — final request to repair five remaining cases (Wayfatours production)
- Sofitel Downtown #70820: the official KGE.room.html detail page has a
  four-image room gallery; its first gallery photo (Accor Scene7
  HCM_P_1942495) is unique and represents the Executive room. The previous
  duplicated hero remains in v1 source metadata, while v2 stores exact room
  page, source SHA-256 and independently-license-unverified status. The new
  verified JPEG is 1200x800. Original JetEngine featured and gallery fields
  were populated; Unit remains Draft.
- Imported Property #36260 (published title #VALUE!, slug value): its
  imported description is about Hotel Stanford, New York; its 3 linked Units
  (#37553, #37554, #37555) contain mismatched room names. All four posts
  were moved from Published to **Draft** without deleting/changing original
  titles, photos, prices (including the pre-existing 100 Unit price), Unit
  relations or metadata. Exact original states are saved in a scoped
  rollback receipt and rollback-preview was successful. The property must
  not be sold as Egyptian accommodation until its identity is verified.
- Three exact-room photos remain **blocked on source verification**,
  with persistent per-Unit _mad4b_stay_room_photo_source_gap_v1 receipts:
  - #70905 Hilton Heliopolis pool-view: the older Hilton image URLs serve
    HTTP 404; no second verifiable room-type image was available.
  - #70893 Fairmont Deluxe Premier Partial Nile: the official detail image
    duplicates an already imported room category. Do not silently crop
    or re-encode the same picture to evade deduplication.
  - #70873 Sonesta Tower Executive: official room API returns a single
    photo already used by another Unit. Wait for a different exact official
    room photo, rather than copy an unrelated image.
- Original image references and rights status are retained. Neither published
  rates nor WooCommerce stock globals were modified. No booking or payment
  capability was activated.
- After this fifth wave: **128 curated Draft Units**, **129 Featured
  Images** and **130 native Gallery fields**, in **148 total Unit records**.
  Four raw non-QA Units still have no imagery (3 valid-room source gaps +
  #37555 quarantined imported room); 14 QA/Test Units also have no photo.
  All three previously published imported Units are now Draft.
- The 129/130 counts include historical existing images/galleries, including
  two quarantined Units; they are NOT 129/130 commercially approved rooms.

## Commercial blocks
Real company-approved selling prices, child/occupancy capacity, verified room variants, Rate validity, the financial ledger and booking authorization remain separate, unresolved work. Draft/QA rates cannot be published automatically.
