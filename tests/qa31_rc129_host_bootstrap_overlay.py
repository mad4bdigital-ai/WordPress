#!/usr/bin/env python3
"""Prove exact host bootstrap addition and reconstruct previously frozen PR813/Original305 entry.

This performs no release authorization, Host enrollment, permission grant,
site mutation or historical source evidence rewrite.
"""
from pathlib import Path
import hashlib
import json
import unittest

ROOT=Path(__file__).resolve().parents[1]
MAIN='wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'
PROOF=ROOT/'docs/release-coverage/pr814-rc129-host-agent-bootstrap-additive-20261118.json'

def blob_sha(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode('ascii')+b'\0'+data).hexdigest()

def strip_rc129_signed_host_overlay(actual):
    """Return exact Original305 predecessor or reject modifications."""
    guard=unittest.TestCase()
    proof=json.loads(PROOF.read_text())
    assert proof.get('contract')=='mad4b.rc129-host-agent-additive-bootstrap-source.v1'
    for field in ('authorizing','production_authorized','host_grant_issued',
                  'public_signer_inherited','release_certificate'):
        guard.assertIs(proof[field],False)
    guard.assertIs(proof['historical_source_review_immutable'],True)
    guard.assertEqual(proof['base_commit'],'27883e019d7b9ddc744f6be33608b78c54ec411b')
    guard.assertEqual(proof['main_path'],MAIN)
    old=(proof['base_main_bytes'],proof['base_main_sha256'],proof['base_main_blob_sha'])
    if (len(actual),hashlib.sha256(actual).hexdigest(),blob_sha(actual))==old:
        return actual
    guard.assertEqual((len(actual),hashlib.sha256(actual).hexdigest(),blob_sha(actual)),
        (proof['current_main_bytes'],proof['current_main_sha256'],proof['current_main_blob_sha']))
    anchor=b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-chatgpt-auto-binding.php';"
    added=(b"\n// Original signed Host WP-CLI transport remains dormant without per-site public trust,\n"
           b"// exact site binding, an independent enrolled NHI and each operation's grant.\n"
           b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-host-agent-bridge.php';")
    guard.assertEqual(hashlib.sha256(anchor).hexdigest(),proof['anchor_sha256'])
    guard.assertEqual((len(added),hashlib.sha256(added).hexdigest()),
        (proof['addition_bytes'],proof['addition_sha256']))
    guard.assertEqual(actual.count(anchor+added),1)
    bridge=ROOT/proof['host_bridge_path']
    guard.assertEqual(proof['host_bridge_path'],
        'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-host-agent-bridge.php')
    contents=bridge.read_bytes()
    guard.assertEqual((len(contents),hashlib.sha256(contents).hexdigest(),blob_sha(contents)),
        (proof['host_bridge_bytes'],proof['host_bridge_sha256'],proof['host_bridge_blob_sha']))
    guard.assertFalse((bridge.parent/'mad4b-host-agent-public.b64').exists())
    for key,path in (
        ('source_regression','wp-content/plugins/mad4b-site-control-plane/tests/host-agent-source-parity-contract.py'),
        ('denial_regression','wp-content/plugins/mad4b-site-control-plane/tests/host-agent-fail-closed-runtime.php')
    ):
        guard.assertEqual(proof[key],path)
        guard.assertTrue((ROOT/path).is_file())
    governance=json.loads((ROOT/'governance/production-readiness/capability-coverage.json').read_text())
    reviewed=[e for e in governance['reviewed_changes']
              if e.get('path')==MAIN and e.get('source_blob_sha')==blob_sha(actual)]
    guard.assertEqual(len(reviewed),1)
    guard.assertEqual(reviewed[0]['status'],'reviewed_for_ci_only')
    guard.assertIs(reviewed[0]['production_authorized'],False)
    guard.assertIs(reviewed[0]['live_evidence_required'],True)
    guard.assertIn(proof['source_regression'],reviewed[0]['regression_tests'])
    restored=actual.replace(anchor+added,anchor,1)
    guard.assertEqual((len(restored),hashlib.sha256(restored).hexdigest(),blob_sha(restored)),old)
    return restored
