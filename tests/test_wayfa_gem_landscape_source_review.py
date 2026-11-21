"""Evidence is reproducible, never a fabricated license or publication receipt."""
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
p=ROOT/'ops/site-company-overlays/wayfatours/media-review/gem-landscape-rights-review-20261116.json'
d=json.loads(p.read_text())
assert d['contract']=='mad4b.media.source-review-candidate.v1'
assert d['site']=='https://wayfatours.com'
assert len(d['target_tours'])==2
assert {x['id'] for x in d['target_tours']}=={60629,60655}
assert all(x['slot']=='grid_image_1_middle_upper' and x['current_origin_id']==60018 for x in d['target_tours'])
s=d['candidate'];assert s['source_not_yet_imported_to_wordpress'] is True
assert s['metadata_conflict_requires_review'] is True
assert len(s['sha256'])==64 and s['downloaded_file_sha1']==s['commons_current_file_sha1']
assert d['rights_backlog']['drafts_missing_author_source_and_license_evidence']==181
assert d['publication_gate']['photo_imported'] is False
assert d['publication_gate']['rights_record_published'] is False
assert d['publication_gate']['original313_readiness_not_claimed'] is True
print('PASS verified GEM evidence remains review-only, exact original image/rights not fabricated')
