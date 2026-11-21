#!/usr/bin/env python3
"""Adversarial exact-source gate for two selective CI-only fixes."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
root=Path(__file__).resolve().parents[1]
manifest=root/'governance/release-parity/qa31-pr990-wayfa-selective-operator-selfupdate-source-20261121.json'
data=json.loads(manifest.read_text())
check=qa.reviewed_pr990_wayfa_selective_operator_selfupdate_source
rootpath=root/'wp-content/plugins/mad4b-site-control-plane'
for path,row in data['source_paths'].items():
    source=(rootpath/path).read_bytes()
    assert check(root,path,source)==row['regression']
    assert check(root,path,source+b'\n// unreviewed mutation') is None
    assert check(root,'includes/class-mad4b-scp-agent-grant-lifecycle.php',source) is None
    original=Path.read_text
    cases=[
      ('release_approved',True),('production_authorized',True),
      ('host_grant_issued',True),('operator_grants_written',True),
      ('update_executed',True),('mail_sent',True),
      ('legacy_v1_signed_plan_modified',True),('untrusted_candidate_promoted',True),
      ('changes_live_wordpress',True),('source_only',False),
      ('reviewed_for_ci_only',False),('live_host_evidence_required',False),
      ('release_gate','APPROVED'),('base_head_sha','0'*40),
      ('prior_review_sha256','0'*64),
      ('adversarial_regression','tests/untrusted.py'),('controls',[])
    ]
    for key,value in cases:
        tampered=dict(data);tampered[key]=value
        def forged(self,*a,**kw):
            return json.dumps(tampered) if self.resolve()==manifest.resolve() else original(self,*a,**kw)
        with mock.patch.object(Path,'read_text',forged):
            assert check(root,path,source) is None,(path,key)
    for key,value in (
      ('original_blob_sha','0'*40),
      ('reviewed_blob_sha','0'*40),
      ('original_sha256','0'*64),
      ('reviewed_sha256','0'*64),
      ('regression','tests/forged.php')
    ):
        tampered=dict(data)
        rows={k:dict(v) for k,v in data['source_paths'].items()}
        rows[path][key]=value
        tampered['source_paths']=rows
        def forged(self,*a,**kw):
            return json.dumps(tampered) if self.resolve()==manifest.resolve() else original(self,*a,**kw)
        with mock.patch.object(Path,'read_text',forged):
            assert check(root,path,source) is None,(path,key)
print('QA31_PR990_WAYFA_SELECTIVE_OPERATOR_SELFUPDATE: PASS (two exact source-only patches)')
