# Saray Pyramids & Museum View — current-vs-legacy taxonomy and photo source review

Date: 20 November 2026. Wayfatours Production Property 36316 / ES 71711. Follow-up to owner identity approval and EN room creation.

## Crosswalk with **no inferred merger**

The currently served official hotel-specific index https://sarayhotels.com/rooms/saray-pyramids-museum-view shows three cards: **Standard room (1 bed)**, **Deluxe room (2 beds)** and **Family rooms (3 beds)**. The separate, formerly indexed https://www.sarayhotels.com/rooms?hotel_id=1 was captured by search indexing as seven categories, but on live HTTP retrieval now serves the redesigned site's template, with **no occurrences of those seven old labels**. Accordingly the indexed seven must not be treated as verified *current* inventory.

| Earlier search-index label | Current candidate | Evidence state |
| --- | --- | --- |
| Standard Double Room | Standard Room #71731 | Tentative lexical similarity only |
| Deluxe Double Room | Deluxe Room #71733 | Tentative lexical similarity only |
| Superior Room Pyramids View | None | No direct match on current three-card listing |
| Family Suite | Family Room #71735 | May be a different category; not merged |
| Family Suite Terrace & Pyramids View | Family Room #71735 | **Conflicting slug**: current index Family card href uses this legacy name with numeric ID 6 |
| Junior Suite Pyramids View | None | No direct match |
| Jacuzzi Suite Pyramids View | None | No direct match |

The Family card's official index links to `/rooms/saray-pyramids-museum-view/family-suite-with-terrace-and-pyramids-view/6` although the hotel-specific page `/rooms/saray-pyramids-museum-view/family-rooms/6` has its own Family rooms title. Different slug responses at numeric room ID 6 make it unsafe to assert those are identical room products or to set an exact family suite classification.

The official hotel's **Download Fact Sheet** link is a one-page PDF with a logo/branding graphic, not an occupancy or room-class matrix. Do not interpret 1/2/3 **beds** on the current cards as guest capacity.

## Official photo evidence saved to WordPress — references only

Three official room detail pages returned HTTP 200. Each has a visually page-associated gallery. The first retrieved JPEG on each page returned **HTTP 200** and the SHA below; the three SHA values are distinct. Only URLs and audit receipts were written under `_mad4b_unit_official_room_photo_reference_v1` and `_mad4b_saray_room_source_media_review_v1`. No JPEG was imported to WordPress and no image permissions were asserted.

| EN Unit | Official page | Image SHA-256 | Found image URLs |
| --- | --- | --- | ---: |
| 71731 Standard | https://sarayhotels.com/rooms/saray-pyramids-museum-view/standard-room/4 | `638891bf73d8e496ccf1bc9634aedfc26be92993f893499ae35fee3b911b5397` | 8 |
| 71733 Deluxe | https://sarayhotels.com/rooms/saray-pyramids-museum-view/deluxe-room/5 | `64685d7b7c68c61058e9bdc2a4fc25768a6e5f01398958a6d553b2b41b38c696` | 6 |
| 71735 Family | https://sarayhotels.com/rooms/saray-pyramids-museum-view/family-rooms/6 | `23a5a6aec27d1295c4b4f3162c5b195050b00e4e679c780504f06128987bf709` | 7 |

**Duplicate-image observation:** Standard room's first and third gallery URLs have identical SHA-256; do not import both as separate pictures.

Native source crosswalk stored on both EN Property #36316 and ES Property #71711 under `_mad4b_saray_room_crosswalk_v1`, with a strict unresolved-mapping and no-legacy-room-auto-create policy. The bounded CLI source script has `plan`, `apply`, and `verify` modes; the completed live verification returned **3/3 image-source references, 0 errors**, with zero images imported.

## Compliance and next admission

The 3 Units remain Published only as internal editorial records, `pending_review`, `unit_available=false`, no verified maximum occupancy, gallery attachments, sell rate, inventory or booking. Site-level `catalog_activated=false` and Property Rates Published=0.

Before activating an exact category or gallery, require an updated hotel room-classification sheet identifying **current room code, room title, bed configurations, number of guests (adult/child) and images usable by Wayfatours**, and reconcile the family slug vs room-title conflict. Legacy indexed rate amounts are not recognized as supplier-negotiated pricing.
