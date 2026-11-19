#!/usr/bin/env python3
"""Narrow, append-only CI review of the PR942 local-Ollama bootstrap removal.

Only one line of the signed PR814 candidate may change. Return the exact old
bootstrap after verification, so every immutable Original305/PR813 certificate
continues to be checked against its ORIGINAL source. This adds no grant.
"""
from pathlib import Path
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
MAIN = 'wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'
PROOF = ROOT/'docs/source-reconciliation/pr942-local-ollama-no-autoboot-20261119.json'

def blob_sha(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode('ascii')+b'\0'+data).hexdigest()

def strip_reviewed_pr942_ollama_delta(actual):
    proof=json.loads(PROOF.read_text(encoding='utf-8'))
    assert proof['contract']=='mad4b.pr942-local-ollama-no-autoboot-additive-source.v1'
    assert proof['main_path']==MAIN
    assert proof['original_source_review_immutable'] is True
    for field in ('authorizing','production_authorized','runtime_activated',
                  'host_grant_issued','owner_attestation_provided'):
        assert proof[field] is False
    old=proof['prior']
    new=proof['reviewed_successor']
    identity=lambda data:(len(data),hashlib.sha256(data).hexdigest(),blob_sha(data))
    assert len(old['sha256'])==64 and len(new['sha256'])==64
    assert len(old['blob_sha'])==40 and len(new['blob_sha'])==40
    old_id=(old['bytes'],old['sha256'],old['blob_sha'])
    new_id=(new['bytes'],new['sha256'],new['blob_sha'])
    if identity(actual)==old_id:
        return actual
    assert identity(actual)==new_id, 'Unreviewed bootstrap identity'
    before=b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-ollama-ability.php';\n"
    after=b'// Local Ollama remains source-only until an explicit local owner activation.\n'
    assert actual.count(after)==1 and actual.count(before)==0
    restored=actual.replace(after,before,1)
    assert identity(restored)==old_id, 'Review delta extends beyond the single Ollama line'
    coverage=json.loads((ROOT/'governance/production-readiness/capability-coverage.json').read_text())
    records=[x for x in coverage['reviewed_changes']
             if x.get('path')==MAIN and x.get('source_blob_sha')==new['blob_sha']]
    assert len(records)==1 and records[0]['status']=='reviewed_for_ci_only'
    assert records[0]['production_authorized'] is False
    assert records[0]['live_evidence_required'] is True
    return restored
