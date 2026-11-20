#!/usr/bin/env python3
"""Independent exact SHA and fraud-denial review of Context title authority."""
import json
import hashlib
import subprocess
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
root=Path(__file__).resolve().parents[1]
name='includes/class-mad4b-scp-context-authority.php'
# Check the historic source at its immutable parent, not a successor HEAD.
# The original SHA review must stay testable after an append-only successor.
original_head='ec422eae1706a697e2b1d379f2dc071c16ba1ceb'
data=subprocess.check_output([
    'git','show',original_head+':wp-content/plugins/mad4b-site-control-plane/'+name
],cwd=root,stderr=subprocess.DEVNULL,timeout=8)
assert hashlib.sha256(data).hexdigest()==('ea788a5b7197991999ed2aff6a9b9e449fafc522eb4654f44d190010b795eff1')
m_path=root/'governance/release-parity/qa31-rc129-context-title-authority-successor-20261119.json'
m=json.loads(m_path.read_text())
assert qa.reviewed_rc129_context_title_authority_successor(root,name,data)==m['required_regression']
assert qa.reviewed_rc129_context_title_authority_successor(root,name,data+b'X') is None
assert qa.reviewed_rc129_context_title_authority_successor(root,'includes/class-mad4b-scp-self-update.php',data) is None
previous=Path.read_text
for field,value in (
    ('authorizing',True),('production_authorized',True),
    ('source_promotion_approved',True),('is_build_provenance',True),
    ('previous_review_sha256','f'*64),
    ('previous_source_sha256','f'*64),
    ('current_source_git_blob_sha','f'*40),
    ('required_regression','tests/not-reviewed.php'),
    ('control_ids',['SECURITY-01']),
    ('release_gate','SOURCE_PARITY_CANDIDATE_UNCERTIFIED'),
):
    fake=dict(m);fake[field]=value
    def replaced(self,*a,**k):
        return json.dumps(fake) if self.resolve()==m_path.resolve() else previous(self,*a,**k)
    with mock.patch.object(Path,'read_text',replaced):
        assert qa.reviewed_rc129_context_title_authority_successor(root,name,data) is None,field
print('QA31_CONTEXT_TITLE_AUTHORITY_SOURCE: PASS (source only, release blocked)')
