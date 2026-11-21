"""Check real editor receipt does not claim imported rights or forged origin."""
import json
from pathlib import Path
R=Path(__file__).resolve().parents[1]
base=R/'ops/site-company-overlays/wayfatours/media-review'
x=json.loads((base/'gem-local-library-reframe-applied-20261116.json').read_text())
c=json.loads((base/'gem-landscape-rights-review-20261116.json').read_text())
assert x['contract']=='mad4b.wayfatours.gem-existing-library-editorial-reframe.v1'
assert x['status']=='manually_applied_with_public_browser_and_photo_quality_readback'
assert x['wpml_attachment_family']=={'en':55107,'es':56582}
assert x['tour_ids']=={'en':60629,'es':60655}
assert x['changed_postmeta_rows']==6 and x['gallery_items_after']==9
assert x['image_original_sha256']==x['focal_point']['bound_original_sha256']
assert x['source_rights_status']=='metadata_pending_nonblocking'
assert x['attribution']['copyright_credit_fabricated'] is False
assert x['attribution']['commons_candidate_unused'] is True
assert x['image_provenance_certified'] is False
assert x['quality_verdict']['gem_crop_open']==0
assert x['quality_verdict']['hero_original_duplicates']==0
assert x['quality_verdict']['gallery_original_duplicates']==0
assert x['cache']['exact_tour_paths_purged']==2
assert all(v['http']==200 and v['photo_visible_as_elementor_background'] for v in x['browser_validation'])
assert c['latest_site_resolution']['status']=='superseded_by_verified_existing_wordpress_library_photo'
assert c['candidate']['source_not_yet_imported_to_wordpress'] is True
assert c['latest_site_resolution']['external_commons_image_imported'] is False
print('PASS GEM existing-library reframe preserves all rights, WPML and rollback distinctions')
