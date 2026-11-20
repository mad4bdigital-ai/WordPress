#!/usr/bin/env python3
"""SHA and governance-fence negative contract for local-only classification repair."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
repo=Path(__file__).resolve().parents[1]
path='includes/class-mad4b-scp-context-authority.php'
manifest=repo/'governance/release-parity/qa31-pr983-wayfa-classification-reconcile-source-20261120.json'
m=json.loads(manifest.read_text())
src=(repo/'wp-content/plugins/mad4b-site-control-plane'/path).read_bytes()
check=qa.reviewed_pr983_wayfa_classification_reconcile_source
assert check(repo,path,src)==m['required_regression']
assert check(repo,path,src+b'\n/* unreviewed edit */') is None
assert check(repo,'includes/class-mad4b-scp-self-update.php',src) is None
original=Path.read_text
for key,value in (
 ('authorizing',True),('production_authorized',True),('source_promotion_approved',True),
 ('is_build_provenance',True),('live_evidence_required',False),
 ('automatic_context_approval_authorized',True),('remote_mutation_ability_added',True),
 ('original_human_review_mutation_authorized',True),('release_gate','PRODUCTION_AUTHORIZED'),
 ('original_github_head_sha','0'*40),('previous_context_title_review_sha256','0'*64),
 ('reviewed_blob_sha','0'*40),('required_regression','tests/forged.php'),
 ('control_ids',['GOVERNANCE-01'])):
    altered=dict(m);altered[key]=value
    def forged(self,*args,**kwargs):
        return json.dumps(altered) if self.resolve()==manifest.resolve() else original(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',forged):
        assert check(repo,path,src) is None,key
print('QA31_WAYFA_CLASSIFICATION_RECONCILE: PASS (exact source pin, tamper denials, release blocked)')
