#!/usr/bin/env python3
"""Independent, exact reviewed company mail SHA and forgery denials."""
import json
import hashlib
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
root=Path(__file__).resolve().parents[1]
name='includes/class-mad4b-scp-company-email-routing.php'
p=root/'wp-content/plugins/mad4b-site-control-plane'/name
data=p.read_bytes()
m_path=root/'governance/release-parity/qa31-rc129-company-email-recipient-safety-successor-20261119.json'
m=json.loads(m_path.read_text())
fn=qa.reviewed_rc129_company_email_recipient_safety_successor
if hashlib.sha256(data).hexdigest()==m['current_source_sha256']:
    # This particular source tree still has the original reviewed blob.
    assert fn(root,name,data)==m['required_regression']
    successor=fn
else:
    # The prior source review is deliberately immutable, but the current
    # source may be its SHA-pinned, independently reviewed safe successor.
    # The successor MUST validate the entire prior signed-off review chain.
    assert fn(root,name,data) is None
    successor=qa.reviewed_rc129_company_email_native_preflight_successor
    assert successor(root,name,data)==m['required_regression']
assert successor(root,name,data+b'UNREVIEWED') is None
assert successor(root,'includes/class-mad4b-scp-self-update.php',data) is None
orig=Path.read_text
for field,value in (
    ('authorizing',True),('production_authorized',True),
    ('source_promotion_approved',True),('is_build_provenance',True),
    ('live_evidence_required',False),('reviewed_for_ci_only',False),
    ('native_notification_writes_added',True),
    ('prior_review_sha256','f'*64),
    ('previous_source_sha256','f'*64),
    ('current_source_git_blob_sha','f'*40),
    ('required_regression','tests/nonexistent.php'),
    ('control_ids',['SECURITY-01']),
    ('release_gate','SOURCE_PARITY_CANDIDATE_UNCERTIFIED'),
):
    forged=dict(m);forged[field]=value
    def replaced(self,*a,**kw):
        return json.dumps(forged) if self.resolve()==m_path.resolve() else orig(self,*a,**kw)
    with mock.patch.object(Path,'read_text',replaced):
        assert successor(root,name,data) is None,field
print('QA31_COMPANY_EMAIL_RECIPIENT_SAFETY: PASS (no mail writes or release authority)')
