#!/usr/bin/env python3
"""Tamper-resistant exact SHA source-only review for the PR937 MCP catalog."""
from pathlib import Path
from unittest import mock
import json
import qa31_release_source_gate as qa
repo=Path(__file__).resolve().parents[1]
name='includes/class-mad4b-scp-server-core-catalog.php'
source=repo/'wp-content/plugins/mad4b-site-control-plane'/name
review=repo/'governance/release-parity/qa31-pr951-pr937-optional-host-catalog-merge-20261120.json'
m=json.loads(review.read_text())
data=source.read_bytes()
test=qa.reviewed_pr937_optional_host_catalog_merge_successor
assert test(repo,name,data)==m['required_regression']
assert test(repo,name,data+b'/* unreviewed */') is None
assert test(repo,'includes/class-mad4b-scp-self-update.php',data) is None
orig=Path.read_text
for field,value in (
    ('authorizing',True),('production_authorized',True),
    ('source_promotion_approved',True),('is_build_provenance',True),
    ('live_evidence_required',False),('reviewed_for_ci_only',False),
    ('original_release_admission_required',False),
    ('exact_original_pr937_head_sha','0'*40),
    ('exact_existing_pr951_head_sha','0'*40),
    ('original_pr951_source_blob_sha','f'*40),
    ('current_source_blob_sha','f'*40),
    ('current_source_sha256','f'*64),
    ('required_regression','tests/not-a-test.php'),
    ('additional_regressions',[]),
    ('control_ids',['SECURITY-01']),
    ('release_gate','RELEASE_AUTHORIZED'),
):
    forged=dict(m);forged[field]=value
    def spoof(self,*a,**kw):
        return json.dumps(forged) if self.resolve()==review.resolve() else orig(self,*a,**kw)
    with mock.patch.object(Path,'read_text',spoof):
        assert test(repo,name,data) is None,field
print('QA31_PR937_OPTIONAL_HOST_CATALOG: PASS (exact parent Git source, no release permission)')
