#!/usr/bin/env python3
"""Negative integrity contract: exact Drive provider + original AI consumer."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa

repo=Path(__file__).resolve().parents[1]
manifest=repo/'governance/release-parity/qa31-pr970-wayfa-drive-exact-evidence-source-20261120.json'
m=json.loads(manifest.read_text())
gate=qa.reviewed_pr970_wayfa_drive_exact_evidence_source
paths=[r['source_path'] for r in m['reviewed_source_entries']]
assert len(paths)==2
for row in m['reviewed_source_entries']:
    path=row['source_path']
    b=(repo/'wp-content/plugins/mad4b-site-control-plane'/path).read_bytes()
    assert gate(repo,path,b)==row['required_regression'],path
    assert gate(repo,path,b+b'\n/* unauthorized source change */') is None,path
    assert gate(repo,'includes/class-mad4b-scp-self-update.php',b) is None,path
original=Path.read_text
for name,forged in (
 ('authorizing',True),('production_authorized',True),
 ('source_promotion_approved',True),('is_build_provenance',True),
 ('live_evidence_required',False),('reviewed_for_ci_only',False),
 ('automated_drive_review_approval_authorized',True),
 ('oauth_refresh_in_read_path_allowed',True),
 ('release_gate','PRODUCTION_AUTHORIZED'),
 ('previous_visual_media_source_review_sha256','0'*64),
 ('original_github_head_sha','0'*40),('negative_contract','tests/forged.py'),
):
    modified=dict(m);modified[name]=forged
    def spoof(self,*a,**kw):
        return json.dumps(modified) if self.resolve()==manifest.resolve() else original(self,*a,**kw)
    with mock.patch.object(Path,'read_text',spoof):
        for path in paths:
            b=(repo/'wp-content/plugins/mad4b-site-control-plane'/path).read_bytes()
            assert gate(repo,path,b) is None,name
for i,row in enumerate(m['reviewed_source_entries']):
    modified=json.loads(json.dumps(m))
    modified['reviewed_source_entries'][i]['reviewed_blob_sha']='f'*40
    def spoof(self,*a,**kw):
        return json.dumps(modified) if self.resolve()==manifest.resolve() else original(self,*a,**kw)
    with mock.patch.object(Path,'read_text',spoof):
        for path in paths:
            b=(repo/'wp-content/plugins/mad4b-site-control-plane'/path).read_bytes()
            assert gate(repo,path,b) is None,'forged_source_entry'
print('QA31_WAYFA_GOOGLE_DRIVE_AI_EVIDENCE: PASS (two exact source SHA policies, tamper denials, no release)')
