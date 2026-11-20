#!/usr/bin/env python3
"""Review original Host UUID site profile predecessor plus exact-source tamper denial."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
root=Path(__file__).resolve().parents[1]
manifest=root/'governance/release-parity/qa31-pr986-wayfa-host-pinned-uuid-source-20261120.json'
m=json.loads(manifest.read_text())
path=m['source_path']
source=(root/'wp-content/plugins/mad4b-site-control-plane'/path).read_bytes()
check=qa.reviewed_pr986_wayfa_host_pinned_uuid_source
assert check(root,path,source)==m['required_regression']
assert check(root,path,source+b'\n// unreviewed change') is None
assert check(root,'includes/class-mad4b-scp-self-update.php',source) is None
original=Path.read_text
for field,value in (
 ('authorizing',True),('production_authorized',True),
 ('source_promotion_approved',True),('is_build_provenance',True),
 ('live_evidence_required',False),('host_uuid_claim_alone_authorizes',True),
 ('existing_profile_rebind_allowed',True),('reserved_uuid_filter_bypassed',True),
 ('dual_uuid_claim_allowed',True),('site_profile_write_performed',True),
 ('host_anchor_issued',True),('release_gate','PRODUCTION_AUTHORIZED'),
 ('original_github_head_sha','0'*40),('prior_context_readback_review_sha256','0'*64),
 ('original_parent_blob_sha','0'*40),('reviewed_sha256','0'*64),
 ('required_regression','tests/forged.php'),('control_ids',[]),
 ('negative_contract','tests/forged.py')
):
 tampered=dict(m);tampered[field]=value
 def replaced(self,*a,**kw):
  return json.dumps(tampered) if self.resolve()==manifest.resolve() else original(self,*a,**kw)
 with mock.patch.object(Path,'read_text',replaced):
  assert check(root,path,source) is None,field
print('QA31_WAYFA_HOST_PINNED_UUID: PASS (immutable parent, no identity or release authority)')
