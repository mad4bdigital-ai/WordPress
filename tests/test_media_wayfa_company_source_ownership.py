"""Company ownership exception map must fail closed on changes or new files."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('media_source_parity',ROOT/'tools/mad4b_media_source_parity.py')
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)

base={'company_overlay':{'status':'exact_live_source_mirror'},'files':[
 {'path':'mu-plugins/shared.php','status':'tracked_same','source_status':'source_matches_live','live_sha256':'A'*64,'git_sha256':'A'*64,'source_sha256':'A'*64},
 {'path':'mu-plugins/company.php','status':'unmapped_git_owner','source_status':'not_in_source','live_sha256':'B'*64,'git_sha256':None,'source_sha256':None},
 {'path':'mu-plugins/pin.php','status':'unreconciled_git_runtime','source_status':'source_matches_live','live_sha256':'C'*64,'git_sha256':'D'*64,'source_sha256':'C'*64},
]}
rows=[{'path':'mu-plugins/company.php','decision':'company_only','owner_scope':'wayfatours_only','site_overlay_immutable_source':True,'live_sha256':'B'*64,'common_candidate_sha256':None,'source_site_expected_sha256':None},
 {'path':'mu-plugins/pin.php','decision':'company_runtime_version_pin','owner_scope':'wayfatours_only','site_overlay_immutable_source':True,'live_sha256':'C'*64,'common_candidate_sha256':'D'*64,'source_site_expected_sha256':'C'*64}]

def check(report,entries):
 with tempfile.TemporaryDirectory() as t:
  p=Path(t)/'owner.json'
  p.write_text(json.dumps({'contract':'mad4b.media.company-scoped-runtime-ownership.v1','site':'https://wayfatours.com','exceptions':entries}))
  return mod.verify_company_ownership(report,p)

assert check(base,rows)['status']=='exact_company_source_ownership_reconciled'
assert check(base,rows)['verified_exceptions']==2
changed=copy.deepcopy(base);changed['files'][1]['live_sha256']='E'*64
assert check(changed,rows)['status']=='hold_for_ownership_reassessment'
changed=copy.deepcopy(base);changed['files'][2]['git_sha256']='E'*64
assert check(changed,rows)['status']=='hold_for_ownership_reassessment'
changed=copy.deepcopy(base);changed['files'].append({'path':'mu-plugins/unknown.php','status':'unmapped_git_owner','source_status':'not_in_source','live_sha256':'F'*64,'git_sha256':None,'source_sha256':None})
assert check(changed,rows)['status']=='hold_for_ownership_reassessment'
changed=copy.deepcopy(base);changed['company_overlay']['status']='mirror_drift'
assert check(changed,rows)['status']=='hold_for_ownership_reassessment'
changed=copy.deepcopy(base);changed['files'][1]['source_status']='source_matches_live'
assert check(changed,rows)['status']=='hold_for_ownership_reassessment'
cross=copy.deepcopy(base)
cross['files'][2]['source_status']='source_differs_from_live'
cross['files'][2]['source_sha256']='Z'*64
cross_rows=copy.deepcopy(rows)
cross_rows[1]['source_site_expected_sha256']='Z'*64
cross_rows[1]['decision']='company_runtime_version_pin_source_diverged'
assert check(cross,cross_rows)['status']=='exact_company_source_ownership_reconciled'
assert check(base,cross_rows)['status']=='hold_for_ownership_reassessment'
assert check(base,rows[:-1])['status']=='hold_for_ownership_reassessment'
assert check(base,[rows[0],rows[0]])['status']=='hold_for_ownership_reassessment'

assert '--require-company-source' in (ROOT/'tools/mad4b_media_source_parity.py').read_text()
print('PASS 9 exact company-owned source scenarios; unknown drift is blocked')
