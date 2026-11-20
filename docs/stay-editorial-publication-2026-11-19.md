# Wayfatours editorial Properties and Units publication — 19 Nov 2026

## User-approved scope
- Set Publish on 8 previously Draft real hotel Properties: IDs 70732-70739.
  The 9 other real Properties linked to Units were already Published. Existing
  Property CPT count becomes 604 Published / 9 Draft.
- Set Publish on 131 previously Draft Units linked by native JetEngine
  relation ID 223 to 17 legitimate hotels. Each Unit has an existing,
  source-traced native Featured Image and unit_gellery field.
- Excluded 14 QA-only Units and their QA Properties. Quarantined imported
  Property 36260 (#VALUE!) and Units 37553,37554,37555 remain Draft.
- Existing Property Rates (28) stay Draft; zero Property Rates Published.
  No Unit sell prices fabricated or changed, stock allotments created,
  WooCommerce global stock setting changed, checkout or payment activated.
- User expressly requested no activation of the separate Property catalog.
  No catalog option, menu/listing, archive registration, CPT configuration
  or frontend template was enabled. HTTP /properties/ and /units/ both
  returned 404 before and after publication. Direct canonical Property and
  Unit URLs also returned 404 in HTTP checks despite post_status=publish.
  Therefore this is WordPress editorial record publication, not confirmation
  of publicly rendered hotel or room pages.

## Evidence and rollback
- Preflight approved exactly 8/8 previously Draft Properties and 131/131
  Units, excluding 14 QA Units and 3 corrupt-import Units by native relations.
- Read-only snapshot of 156 prior posts (titles, statuses, slugs, dates,
  Featured Images, gallery and prices) stored on authorized VPS at:
  /opt/mad4b-company-provisioning/reports/stay-editorial-publish-20261119/before-publication-posts.json
- Applied bounded WP-CLI status writes on Wayfatours Production. Each of
  the 139 newly published posts has additive rollback metadata
  _mad4b_stay_editorial_published_v1; shared publication plan is stored as
  site option mad4b_stay_editorial_publish_v1 (catalog_activated=false).
- Helper is scoped to exact site and IDs, requires owner approval, checks
  relations, media integrity, QA, quarantined records and stops if commercial
  Property Rates are already Published.
- Read-only rollback-preview succeeded for both Properties and Units;
  actual rollback was not performed. Rollback refuses posts modified since
  the publication. Photos, media attachments and prices are unchanged.
- Post-publication live audit: 131/131 eligible Units Published with receipts,
  8/8 intended Properties Published with receipts, zero media missing.
  All 131 readiness evaluations report catalog_ready_for_review=false and
  checkout_ready=false. QA Units and invalid-import quartet stay Draft.
  Published Property Rates remain zero.
- The underlying Properties CPT is already public and has_archive=true, but
  its public archive HTTP endpoint still returns 404. We did not modify CPT
  or archive behavior. Do not confuse post_status=publish with enabling the
  frontend catalog.
