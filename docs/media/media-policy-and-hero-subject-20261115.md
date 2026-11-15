# MAD4B Media — dynamic readiness, governed review and hero framing

Validated 2026-11-15 against live WordPress databases and native Elementor
rendering for Wayfatours Production and Tours.dev.

## Actual incident and governed correction

Wayfatours Tour 60439 and WPML Spanish translation 60609 reused the
same photo origin Attachment 60022 in the image_vertical and grid_image_2
fields. The existing quality auditor correctly reported
media_hero_original_duplicate.

The original MAD4B Tour Media Governed Apply replaced grid_image_2 on
both localized Tour posts with the distinct local Egyptian Museum image
Attachment 16534. The picture was already in the signed Tour media
association and gallery. Native dry run passed and application produced
rollback token 1f26b21e-7e18-40ed-98ee-541b622279da.
Native cache purge was limited to both public Tour URLs. The five visible
hero slots now use five distinct verified photo-origin hashes. No
original gallery, portrait, other grid image, alt text or Elementor
template was modified.

One separate photo had a focal/framing defect: Aswan boats were hidden
by center positioning under background-size:cover. The existing Tour
Gallery Popup Layout module now supports bounded presentation-only focal
coordinates in per-Tour _mad4b_media_focal_points_v1, with filter
mad4b/media/frame_focal_policy. The reference Tour and ES translation
set grid_image_4_ to x=50, y=100. Neither source imagery nor popup
content changed. Focal metadata is NOT evidence of licensing or
geographic authenticity.

## Dynamic extensions to existing MAD4B engines

1. Universal Media Readiness enrolls public post types and taxonomies
   via actual WordPress registration. Safe optional type_overrides are
   read from the existing company_media_governance_policy_v1 option;
   supported keys: scan, required, commercial, field, scan_limit.
   A typed selector post:<type> or term:<taxonomy> takes precedence
   over the plain type key; the original
   mad4b/universal_media/policy filter remains active.
   commercial_media_rights_review_required is independent of whether
   the file exists or renders. Rights remain unverified and auto apply
   is never authorized by this signal alone.
2. Tour Photo Quality discovers existing grid_image_* fields from Tour
   post meta and provider filter mad4b/tour_photo_quality/hero_fields.
   It preserves original-lineage duplicate checks and the Source's
   newer bulk_reuse_evidence / foreign-gallery review handling.
   New signals media_hero_focal_review_pending and
   media_hero_subject_review_pending reflect absent focal metadata
   and missing per-photo visual review; neither silently replaces
   images. A per-photo subject evidence contract is checked against
   site UUID, slot field, original SHA and bounded review fingerprint.
   Alt text and filenames are not geographic or licensing proof.
3. Universal Media Apply advertises composite Adapters only if native
   MAD4B_Composite_Media_Adapter_V1 is actually loaded. Therefore
   Wayfatours currently exposes 7 adapters and Tours.dev exposes 5;
   neither duplicates nor invents a missing Provider.
4. Existing hourly Photo Quality scanner scales bounded batch size
   with the number of published Tours, within 1 to 50 records/hour,
   using the native cursor and per-Tour change events. Policy filter
   mad4b/tour_photo_quality/scan_batch_limit can lower the ceiling.
   WordPress cron timing depends on scheduling and site requests;
   large catalogs can take multiple cycles and are not immediately
   fully reviewed by one deployment.

## Readbacks and remaining editorial blockers

- Live module files have matching SHA-256 on both sites.
- Native read-only WP-CLI tests pass for company policy override isolation,
  public taxonomy enrollment, rights-vs-media readiness, original-photo
  uniqueness and actual Adapter registration.
- Browser tested five Elementor preview frames, original Popup opening,
  focal point on Wayfatours EN+ES, and zero JavaScript exceptions.
- A 12-Tour English sample on each site still had review signals:
  other Wayfatours Tour hero duplicates and Source imported foreign
  mass Gallery reuse. These are inspection results, not permission
  to bulk-replace, delete or republish imagery.
- Rights mode remains advisory on both sites. This implementation does
  NOT certify commercial licensing or switch the site to blocking all
  existing images.
- The separate Company Media Governance module is present on the source
  but not copied wholesale to Wayfatours. Shared option/filter policies
  are used without overwriting differently owned live modules.

## Test and rollback

Read-only:
wp eval-file scripts/qa/media-quality-dynamic-contract.php
python3 scripts/qa/gallery-focal-browser-contract.py

VPS original module backups are private under:
 /opt/mad4b-company-transport/backups/media-scalable-policy-20261115
 /opt/mad4b-company-transport/backups/media-scalable-presentation-20261115

Tour image rollback must use the exact original MAD4B Governed Apply
receipt after verifying company and Tour identity. Do not casually edit
original WPML relationships or overwrite Gallery values.

Git synchronization is not signed Original313 release authorization.
