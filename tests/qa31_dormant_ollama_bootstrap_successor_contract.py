#!/usr/bin/env python3
"""Non-authorizing exact original305 Ollama dormant successor contract."""
from pathlib import Path
from unittest import mock
import json,hashlib
import qa31_release_source_gate as qa
repo=Path(__file__).resolve().parents[1]
plugin=repo/'wp-content/plugins/mad4b-site-control-plane'
data=(plugin/'mad4b-site-control-plane.php').read_bytes()
old=b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-ollama-ability.php';"
comment=b'// Local Ollama remains source-only until an explicit local owner activation.'
m_path=repo/'governance/release-parity/qa31-rc129-dormant-ollama-bootstrap-successor-20261119.json'
m=json.loads(m_path.read_text())
assert data.count(comment)==1 and old not in data
assert hashlib.sha256(data).hexdigest()==m['current_sha256']
assert qa.reviewed_rc129_dormant_ollama_bootstrap_successor(repo,'mad4b-site-control-plane.php',data)==m['coverage_regression']
assert qa.reviewed_rc129_dormant_ollama_bootstrap_successor(repo,'includes/class-mad4b-scp-context-authority.php',data) is None
assert qa.reviewed_rc129_dormant_ollama_bootstrap_successor(repo,'mad4b-site-control-plane.php',data+b'\n') is None
assert qa.reviewed_rc129_dormant_ollama_bootstrap_successor(repo,'mad4b-site-control-plane.php',data.replace(comment,old)) is None
original=Path.read_text
for key,value in (
 ('production_authorized',True),
 ('source_promotion_approved',True),
 ('is_build_provenance',True),
 ('release_gate','SOURCE_PARITY_CANDIDATE_UNCERTIFIED'),
 ('current_git_blob_sha','f'*40),
 ('previous_sha256','f'*64),
 ('previous_review_sha256','f'*64),
 ('control_ids',['AI-01']),
 ('required_regression','tests/not-reviewed.py'),
 ('coverage_regression','tests/not-reviewed.py'),
):
    forged=dict(m);forged[key]=value
    def replaced(self,*a,**kw):
        return json.dumps(forged) if self.resolve()==m_path.resolve() else original(self,*a,**kw)
    with mock.patch.object(Path,'read_text',replaced):
        assert qa.reviewed_rc129_dormant_ollama_bootstrap_successor(repo,'mad4b-site-control-plane.php',data) is None,key
print('QA31_DORMANT_OLLAMA_EXACT_SOURCE: PASS (no runtime or release authority)')
