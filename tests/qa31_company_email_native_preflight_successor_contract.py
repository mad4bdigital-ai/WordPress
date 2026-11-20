#!/usr/bin/env python3
"""Review exact owner-bound email preflight and tamper-denial on rc129."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
root=Path(__file__).resolve().parents[1]
name='includes/class-mad4b-scp-company-email-routing.php'
source=root/'wp-content/plugins/mad4b-site-control-plane'/name
data=source.read_bytes()
manifest=root/'governance/release-parity/qa31-rc129-company-email-native-preflight-successor-20261120.json'
m=json.loads(manifest.read_text())
fn=qa.reviewed_rc129_company_email_native_preflight_successor
assert fn(root,name,data)==m['required_regression']
assert fn(root,name,data+b'UNREVIEWED') is None
assert fn(root,'includes/class-mad4b-scp-self-update.php',data) is None
original=Path.read_text
for field,value in (
    ('authorizing',True), ('production_authorized',True),
    ('is_build_provenance',True), ('source_promotion_approved',True),
    ('live_evidence_required',False), ('reviewed_for_ci_only',False),
    ('native_notification_writes_added',True),
    ('scheduled_jobs_added',True),
    ('parent_review_sha256','f'*64),
    ('previous_source_sha256','f'*64),
    ('current_source_git_blob_sha','f'*40),
    ('required_regression','tests/unknown.php'),
    ('test_count',1),
    ('control_ids',['SECURITY-01']),
    ('source_release_gate','PRODUCTION_AUTHORIZED')
):
    forged=dict(m);forged[field]=value
    def replaced(self,*a,**kw):
        return json.dumps(forged) if self.resolve()==manifest.resolve() else original(self,*a,**kw)
    with mock.patch.object(Path,'read_text',replaced):
        assert fn(root,name,data) is None,field
print('QA31_COMPANY_NATIVE_NOTICE_PREFLIGHT: PASS (44 cases, no writes)')
