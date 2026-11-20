"""
PR814 source-only additive overlay guard for the live Original305 review bootstrap.
A new source overlay must be exact, separately SHA-pinned, and non-authorizing;
all frozen PR808/PR813 historical certificates remain untouched.
"""
from pathlib import Path
import hashlib
import json
import unittest
from qa31_rc129_host_bootstrap_overlay import strip_rc129_signed_host_overlay
from cwv_site_admission_exact_overlay import strip_exact_site_admission

ROOT = Path(__file__).resolve().parents[1]
MAIN = 'wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'

def restore_exact_original305_overlay(actual, meta):
    """Restore the frozen source only after verifying a separately reviewed,
    narrow 7-line boot overlay; never repin PR808/PR813 certificates."""
    guard=unittest.TestCase()
    actual=strip_exact_site_admission(actual)
    actual=strip_rc129_signed_host_overlay(actual)
    if (len(actual), hashlib.sha256(actual).hexdigest()) == (
            meta['current_bytes'], meta['current_sha256']):
        return actual
    proof_path=ROOT/'docs/release-coverage/pr814-original305-host-review-additive-20261118.json'
    guard.assertTrue(proof_path.is_file(), 'Original305 source overlay certificate absent')
    proof=json.loads(proof_path.read_text())
    guard.assertEqual(proof['contract'], 'mad4b.original305-source-review-bootstrap-additive.v1')
    for key in ('authorizing','production_authorized','master_signed_envelope_issued','host312_authorized'):
        guard.assertIs(proof[key],False)
    guard.assertIs(proof['historical_source_review_immutable'],True)
    guard.assertIs(proof['live_evidence_required'],True)
    guard.assertEqual(proof['historical_review'],
        'docs/release-coverage/pr814-pr813-destination-preview-additive-20261114.json')
    guard.assertEqual(proof['main_path'],MAIN)
    guard.assertEqual(proof['base_commit'],'de85fdf39169737d17be8d2473ab4f53350d1ba2')
    guard.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),
        (proof['additive_current_bytes'],proof['additive_current_sha256']))
    blob=hashlib.sha1(b'blob '+str(len(actual)).encode('ascii')+b'\0'+actual).hexdigest()
    guard.assertEqual(blob, proof['additive_current_git_blob_sha'])
    governance=json.loads((ROOT/'governance/production-readiness/capability-coverage.json').read_text())
    rows=[r for r in governance['reviewed_changes']
          if r.get('path')==MAIN and r.get('source_blob_sha')==blob]
    guard.assertEqual(len(rows),1)
    guard.assertEqual(rows[0]['status'],'reviewed_for_ci_only')
    guard.assertIs(rows[0]['production_authorized'],False)
    anchor=b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-approval-tickets.php';\n"
    addition=(b"// Read-only original Host source-review registration; execution remains denied\n"
        b"// unless independently governed Host authorities issue and verify signed proof.\n"
        b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-host-source-release-review.php';\n"
        b"MAD4B_SCP_Native_Host_Source_Release_Review::boot();\n"
        b"// Original305 incident approvals are ticket decisions only, not Host grants.\n"
        b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-original305-incident-review.php';\n"
        b"MAD4B_SCP_Original305_Incident_Review::boot();\n")
    guard.assertEqual(hashlib.sha256(anchor).hexdigest(),proof['exact_anchor_sha256'])
    guard.assertEqual((len(addition),len(addition.splitlines()),hashlib.sha256(addition).hexdigest()),
        (proof['exact_addition_bytes'],proof['exact_addition_lines'],proof['exact_addition_sha256']))
    guard.assertEqual(actual.count(anchor+addition),1)
    guard.assertEqual(len(proof['retained_modules']),2)
    for row in proof['retained_modules']:
        path=row['path']
        content=(ROOT/path).read_bytes()
        guard.assertEqual((len(content),hashlib.sha256(content).hexdigest()),
            (row['bytes'],row['sha256']))
        mod_blob=hashlib.sha1(b'blob '+str(len(content)).encode('ascii')+b'\0'+content).hexdigest()
        guard.assertEqual(mod_blob,row['git_blob_sha'])
    historic=actual.replace(anchor+addition,anchor,1)
    guard.assertEqual((len(historic),hashlib.sha256(historic).hexdigest()),
        (meta['current_bytes'],meta['current_sha256']))
    guard.assertEqual((len(historic),hashlib.sha256(historic).hexdigest()),
        (proof['historical_current_bytes'],proof['historical_current_sha256']))
    return historic
