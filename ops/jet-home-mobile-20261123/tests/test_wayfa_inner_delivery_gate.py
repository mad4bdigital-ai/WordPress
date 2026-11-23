"""Regression guard: Wayfatours must never silently truncate the first signed JetEngine response."""
from pathlib import Path
import hashlib
import json
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
FILE = ROOT / 'ops/site-company-overlays/wayfatours/performance-runtime/wp-content/mu-plugins/mad4b-secondary-listing-lazy.php'
MANIFEST = Path(__file__).resolve().parents[1] / 'release.json'

class OriginalPaginationGuardTest(unittest.TestCase):
    def test_live_exact_release(self):
        data = FILE.read_bytes()
        receipt = json.loads(MANIFEST.read_text())
        self.assertEqual(hashlib.sha256(data).hexdigest(), receipt['code']['live_after_sha256'])

    def test_unproved_partial_delivery_denied(self):
        code = FILE.read_text()
        proof_start = code.index('function mad4b_secondary_grid_inner_completed_delivery_proven(')
        admitted = code.index('function mad4b_secondary_grid_inner_admit(')
        before_return = code[admitted:code.index('return $batch;', admitted)]
        self.assertIn('mad4b_secondary_grid_inner_completed_delivery_proven(', before_return)
        self.assertRegex(before_return, r'if\s*\(\s*!\s*mad4b_secondary_grid_inner_completed_delivery_proven\(')
        self.assertIn('return 0;', before_return)
        proof_code = code[proof_start:admitted]
        for proof in ('native-signed-get-listing-and-load-more', 'terminal_page_verified',
                      'same_order_and_content_as_ssr', 'query_generation', 'all_pages_items',
                      "$proof['ssr_'.$part.'_sha256']", "$proof['loaded_'.$part.'_sha256']"):
            self.assertIn(proof, proof_code)

    def test_no_forced_proof_grant(self):
        code = FILE.read_text()
        self.assertIn("get_option('mad4b_secondary_grid_inner_pagination_proofs_v1',[])", code)
        self.assertNotIn('return true; // bypass proof', code)

if __name__ == '__main__':
    unittest.main()
