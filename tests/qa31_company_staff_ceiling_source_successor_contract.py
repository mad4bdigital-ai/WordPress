#!/usr/bin/env python3
"""QA31 exact Master Admin source ceiling, no Production permission."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
repo=Path(__file__).resolve().parents[1]
p='includes/class-mad4b-scp-company-master-admin.php'
plugin=repo/'wp-content/plugins/mad4b-site-control-plane'
data=(plugin/p).read_bytes()
m_path=repo/'governance/release-parity/qa31-rc129-company-staff-ceiling-source-successor-20261119.json'
manifest=json.loads(m_path.read_text())
assert qa.reviewed_rc129_company_staff_ceiling_successor(repo,p,data)==manifest['required_regression']
assert qa.reviewed_rc129_company_staff_ceiling_successor(repo,p,data+b'\nFORGED') is None
assert qa.reviewed_rc129_company_staff_ceiling_successor(repo,'includes/class-mad4b-scp-skills-admin-ui.php',data) is None
for k,v in (
 ('authorizing',True),('production_authorized',True),
 ('source_promotion_approved',True),('is_build_provenance',True),
 ('release_gate','SOURCE_PARITY_CANDIDATE_UNCERTIFIED'),
 ('historical_qa31_sha256','f'*64),('current_blob_sha','f'*40),
 ('previous_sha256','f'*64),('control_ids',['SECURITY-01']),
 ('required_regression','tests/not-reviewed.py')):
    forged=dict(manifest);forged[k]=v
    original=Path.read_text
    def replaced(self,*a,**kw):
        return json.dumps(forged) if self.resolve()==m_path.resolve() else original(self,*a,**kw)
    with mock.patch.object(Path,'read_text',replaced):
        assert qa.reviewed_rc129_company_staff_ceiling_successor(repo,p,data) is None,k
print('QA31_MASTER_ADMIN_ROLE_CEILING: PASS (no release authority)')
