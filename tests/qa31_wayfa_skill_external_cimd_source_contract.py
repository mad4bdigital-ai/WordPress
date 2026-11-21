#!/usr/bin/env python3
"""Tamper denials for exact first-party scoped ChatGPT client Skills review."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa

root=Path(__file__).resolve().parents[1]
manifest=root/'governance/release-parity/qa31-pr995-wayfa-skill-cimd-source-20261121.json'
m=json.loads(manifest.read_text())
path=m['source_path']
source=(root/'wp-content/plugins/mad4b-site-control-plane'/path).read_bytes()
verify=qa.reviewed_pr995_wayfa_skill_cimd_source
assert verify(root,path,source)==m['required_regression']
assert verify(root,path,source+b'\n// unauthorized') is None
assert verify(root,'includes/class-mad4b-scp-local-oauth-server.php',source) is None
original=Path.read_text
for key,value in (
 ('authority_issued',True),('production_authorized',True),
 ('release_approved',True),('grants_created',True),
 ('oauth_client_registered',True),('external_snapshot_persisted',True),
 ('live_wordpress_modified',True),('source_only',False),
 ('reviewed_for_ci_only',False),('live_host_evidence_required',False),
 ('release_gate','APPROVED'),('base_head_sha','0'*40),
 ('prior_source_review_sha256','0'*64),('original_parent_sha256','0'*64),
 ('reviewed_sha256','0'*64),('negative_contract','tests/fake.py'),
 ('required_regression','tests/fake.php'),('control_ids',[])):
    tampered=dict(m);tampered[key]=value
    def forged(self,*a,**kw):
        return json.dumps(tampered) if self.resolve()==manifest.resolve() else original(self,*a,**kw)
    with mock.patch.object(Path,'read_text',forged):
        assert verify(root,path,source) is None,key
print('QA31_WAYFA_SCOPED_CIMD_SKILLS: PASS (immutable old/new SHA, no OAuth or release authority)')
