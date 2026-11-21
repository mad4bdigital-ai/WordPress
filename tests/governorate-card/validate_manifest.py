"""Fast offline regression: destination photo sources and presentation contract."""
import json
from pathlib import Path
R=Path(__file__).resolve().parents[2]
manifest=json.loads((R/'ops/media-audits/governorate-photos-20261117.json').read_text())
source=(R/'wp-content/mu-plugins/mad4b-governorate-scenic-card-v1.php').read_bytes()
overlay=(R/'ops/site-company-overlays/wayfatours/media-runtime/wp-content/mu-plugins/mad4b-governorate-scenic-card-v1.php').read_bytes()
assert source==overlay, 'Source/Wayfatours visual policies drifted'
assert b'elementor-element-b25dbff' in source and b'flex-end!important' in source
assert b'is_front_page()' in source and b'wpml_object_id' in source
entries=manifest['entries'];assert len(entries)==17
assert len([x for x in entries if x['review_status']=='licensed_photo_applied_verified'])==10
assert len([x for x in entries if x['review_status']=='existing_photo_retained'])==7
for x in entries:
 if x['review_status']=='licensed_photo_applied_verified':
  a=x['selected_image'];assert a['source_url'].startswith('https://commons.wikimedia.org/wiki/File:')
  assert a['license_url'].startswith('https://creativecommons.org/licenses/')
  assert a['license'].startswith('CC BY') and len(a['sha256'])==64 and a['creator']
 else:
  assert x['reason']
assert manifest['wpml_verified_terms']=={'source':59,'wayfatours':20}
print('PASS: 17 terms / 10 Commons-licensed / 7 retained / 79 language relationships / CSS parity')
