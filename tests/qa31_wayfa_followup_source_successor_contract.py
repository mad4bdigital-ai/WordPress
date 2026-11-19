#!/usr/bin/env python3
"""Two reviewed exact source paths remain non-authorizing under QA31."""
import json
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
root=Path(__file__).resolve().parents[1]
plugin=root/'wp-content/plugins/mad4b-site-control-plane'
m_path=root/'governance/release-parity/qa31-rc129-wayfa-followup-source-successors-20261119.json'
data=json.loads(m_path.read_text())
for row in data['reviewed_entries']:
    path=row['path']
    contents=(plugin/path).read_bytes()
    assert qa.reviewed_rc129_wayfa_source_followup_successor(root,path,contents)==row['required_regression'],path
    assert qa.reviewed_rc129_wayfa_source_followup_successor(root,path,contents+b'\nMODIFIED') is None,path
assert qa.reviewed_rc129_wayfa_source_followup_successor(root,'includes/forged.php',b'') is None
alerts=(plugin/'includes/class-mad4b-scp-content-readiness-alerts.php').read_text()
assert 'const MAX_ISSUES = 6000;' in alerts and 'get_edit_term_link(' in alerts
skills=(plugin/'includes/class-mad4b-scp-skills-admin-ui.php').read_text()
native=(plugin/'includes/class-external-skill-sources.php').read_text()
assert "current_user_can( 'mad4b_manage_company_operations' )" in skills
assert "current_user_can( 'manage_options' )" in skills
assert "SCP_External_Skill_Sources::handle_admin( 'add', $_POST )" in skills
assert "check_admin_referer( 'external_skill_sources', 'external_skill_sources_nonce' )" in native
original=Path.read_text
for field,value in (
    ('authorizing',True),
    ('production_authorized',True),
    ('source_promotion_approved',True),
    ('is_build_provenance',True),
    ('release_gate','SOURCE_PARITY_CANDIDATE_UNCERTIFIED'),
    ('prior_review_sha256','f'*64),
    ('exact_source_files',3),
):
    forged=dict(data);forged[field]=value
    def replaced(self,*a,**k):
        return json.dumps(forged) if self.resolve()==m_path.resolve() else original(self,*a,**k)
    with mock.patch.object(Path,'read_text',replaced):
        for row in data['reviewed_entries']:
            assert qa.reviewed_rc129_wayfa_source_followup_successor(root,row['path'],(plugin/row['path']).read_bytes()) is None,field
for field,value in (
    ('current_git_blob_sha','f'*40),
    ('parent_git_blob_sha','e'*40),
    ('required_regression','tests/not-reviewed.py'),
    ('control_ids',['SECURITY-01']),
):
    forged=json.loads(json.dumps(data))
    forged['reviewed_entries'][0][field]=value
    def replaced(self,*a,**k):
        return json.dumps(forged) if self.resolve()==m_path.resolve() else original(self,*a,**k)
    with mock.patch.object(Path,'read_text',replaced):
        row=data['reviewed_entries'][0]
        assert qa.reviewed_rc129_wayfa_source_followup_successor(root,row['path'],(plugin/row['path']).read_bytes()) is None,field
print('QA31_WAYFA_FOLLOWUP_TWO_SOURCE_REVIEWS: PASS (no release authority)')
