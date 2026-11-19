"""Portable source-only contract for legacy Original305 signed review request."""
from __future__ import annotations
import ast
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
CODE=ROOT/'ops/native-source-publisher-v1/original305_incident_signed_review_v1.py'

class Original305ReviewAuthority(unittest.TestCase):
    def test_has_exact_bounded_approval_fingerprint(self):
        content=CODE.read_text();ast.parse(content)
        for value in ("ABILITY = 'mad4b/original305-incident-review'",
                      "ACTION = 'review_legacy_ambiguous_signed_write'",
                      'legacy_hold_sha256','registry_sha256','supervisor_sha256',
                      'request_sha256','INCIDENT_MASTER_APPROVAL_BINDING_CHANGED',
                      'INCIDENT_MASTER_SIGNATURE_INVALID',
                      'Ed25519PublicKey.from_public_bytes',
                      'expiry-start<=900',
                      "'original_wordpress_ticket_admission_verified':False"):
            self.assertIn(value,content)
    def test_no_signer_no_close_no_timer_side_effects(self):
        content=CODE.read_text()
        for forbidden in ('original_signer(', 'HOLD.unlink(', 'HOLD.rename(',
                          'HOLD.write_bytes(', 'systemctl enable',
                          'subprocess.run(', 'update_option(', '--close', '--apply'):
            self.assertNotIn(forbidden,content)
        self.assertIn("g.add_argument('--plan'",content)
        self.assertIn("g.add_argument('--verify-approval'",content)
        self.assertIn("'incident_closed':False",content)
        self.assertIn("'host_release_authorized':False",content)

if __name__=='__main__':unittest.main()
