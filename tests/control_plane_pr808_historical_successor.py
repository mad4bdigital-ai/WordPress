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
    actual=(ROOT/actual_path).read_bytes()
    assert sha(actual)==row['current_source_sha256']
    assert row['review_class']=='exact_source_regression_only_not_signed_release'
    for witness in row['fail_closed_code_witnesses']:
        assert witness.encode() in actual,(actual_path,witness)
    return row
