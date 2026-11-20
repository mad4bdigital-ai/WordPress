#!/usr/bin/env python3
"""Exact original UI parent and false-freshness/false-authority tamper denial."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
root=Path(__file__).resolve().parents[1]
m_path=root/'governance/release-parity/qa31-pr985-wayfa-indexed-scan-readback-source-20261120.json'
m=json.loads(m_path.read_text())
path=m['source_path']
data=(root/'wp-content/plugins/mad4b-site-control-plane'/path).read_bytes()
check=qa.reviewed_pr985_wayfa_indexed_scan_readback_source
assert check(root,path,data)==m['required_regression']
assert check(root,path,data+b'\n// unreviewed change') is None
assert check(root,'includes/class-mad4b-scp-content-jobs.php',data) is None
original=Path.read_text
for field,value in (
 ('authorizing',True),('production_authorized',True),
 ('source_promotion_approved',True),('is_build_provenance',True),
 ('live_evidence_required',False),('oauth_refresh_on_get_allowed',True),
 ('scan_on_get_allowed',True),('automatic_context_approval_authorized',True),
 ('historical_readback_grants_drive_freshness',True),
 ('release_gate','PRODUCTION_AUTHORIZED'),
 ('original_github_head_sha','0'*40),('reviewed_sha256','0'*64),
 ('previous_context_classification_review_sha256','0'*64),
 ('original_parent_blob_sha','0'*40),
 ('required_regression','tests/forged.php'),('control_ids',[]),
 ('negative_contract','tests/fake.py')
):
 forged=dict(m);forged[field]=value
 def fake(self,*args,**kwargs):
  return json.dumps(forged) if self.resolve()==m_path.resolve() else original(self,*args,**kwargs)
 with mock.patch.object(Path,'read_text',fake):
  assert check(root,path,data) is None,field
print('QA31_WAYFA_INDEXED_SCAN_READBACK: PASS (source-only, no fresh Drive authority)')
