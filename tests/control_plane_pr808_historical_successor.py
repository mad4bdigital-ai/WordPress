"""Bind immutable PR808 historical source to reviewed current guarded successors.

The source-only evidence is not an owner signature, grant or release receipt.
Never mutate the original PR808 review JSON to re-pin a new SHA.
"""
from pathlib import Path
import hashlib
import json
import subprocess

ROOT=Path(__file__).resolve().parents[1]
REVIEW=ROOT/'docs/release-coverage/PR808_CODE_SOURCE_SUCCESSOR_20261116.json'

def sha(b):
    return hashlib.sha256(b).hexdigest()

def assert_source_successor(actual_path, historical_sha):
    record=json.loads(REVIEW.read_text())
    assert record['contract']=='mad4b.pr808-historical-code-fingerprint-successor.source-only.v1'
    for field in ('immutable_historical_reviews_modified',
                  'current_source_promoted_to_live','owner_signed_approval_issued',
                  'original313_release_receipt_issued'):
        assert record[field] is False,field
    row=record['source_successors'][actual_path]
    assert row['historical_source_sha256']==historical_sha
    commit=record['historical_git_commit']
    result=subprocess.run(['git','-C',str(ROOT),'show',commit+':'+actual_path],
                          capture_output=True,check=True,timeout=20)
    assert sha(result.stdout)==historical_sha
    path=ROOT/actual_path
    assert path.is_file() and not path.is_symlink()
    actual=path.read_bytes()
    current_digest=sha(actual)
    if current_digest!=row['current_source_sha256']:
        # Keep the PR808 successor immutable. Later source revisions MUST
        # carry a non-authorizing, exact Git-blob review in the append-only
        # central Readiness source ledger. CI tests its linked regressions.
        # This is NOT an Owner attestation or a runtime grant.
        coverage=json.loads((ROOT/'governance/production-readiness/capability-coverage.json').read_text(encoding='utf-8'))
        assert coverage['contract']=='mad4b.readiness-capability-coverage.v1'
        assert coverage['authorizing'] is False and coverage['production_promotion_from_ci'] is False
        committed=subprocess.run(['git','-C',str(ROOT),'rev-parse','HEAD:'+actual_path],
                                 capture_output=True,check=True,timeout=20).stdout.decode().strip()
        worktree=subprocess.run(['git','-C',str(ROOT),'hash-object',actual_path],
                                capture_output=True,check=True,timeout=20).stdout.decode().strip()
        assert len(committed)==40 and committed==worktree
        matches=[entry for entry in coverage['reviewed_changes']
                 if entry.get('path')==actual_path and entry.get('source_blob_sha')==committed]
        assert len(matches)==1,'Unreviewed exact successor source: '+actual_path
        successor=matches[0]
        assert successor['status']=='reviewed_for_ci_only'
        assert successor['production_authorized'] is False and successor['live_evidence_required'] is True
        assert {'MCP-01','EXECUTION-01'} <= set(successor['control_ids'])
        assert len(successor['review_rationale'])>=24 and successor['owner_role']
        linked=successor['regression_tests']
        assert isinstance(linked,list) and linked
        assert all(isinstance(test,str) and (test.startswith('tests/') or test.startswith('wp-content/plugins/mad4b-site-control-plane/tests/'))
                   and (ROOT/test).is_file() for test in linked)
    assert row['review_class']=='exact_source_regression_only_not_signed_release'
    for witness in row['fail_closed_code_witnesses']:
        assert witness.encode() in actual,(actual_path,witness)
    return row
