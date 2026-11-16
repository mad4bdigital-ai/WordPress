"""Exact source/live cache forward merge, isolated and non-authorizing."""
from pathlib import Path
import hashlib
import json
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[1]
REL=Path('wp-content/plugins/mad4b-cache-lifecycle/includes/runtime.php')
UPSTREAM_RATE_SHA256='6ec32f43787cdaab1e2c7c1d3d668a3cbeb1b2ca473b0d037ffb1feff51dd233'
OLD='aad979f9979f061a046407f6a6263f33e970f9bcf2f0b6fd8347199a38f72ebf'
BASE='a05abada55acb87d112d60606b51cef8b82a4af9'

class CacheForwardParity(unittest.TestCase):
    def test_candidate_pinned_from_reviewed_live_source(self):
        raw=(ROOT/REL).read_bytes()
        # Exact source delta: the reviewed new rate-routing behavior is
        # preserved. Only runtime PHP 7.4 prefix syntax compatibility changes.
        restored=raw.decode()
        conversions={
         "(0===strpos((string)($a['parent_object']??''),'posts::'))":"str_starts_with((string)($a['parent_object']??''),'posts::')",
         "(0===strpos((string)($a['child_object']??''),'posts::'))":"str_starts_with((string)($a['child_object']??''),'posts::')",
         "(0===strpos((string)($a['child_object']??''),'cct::'))":"str_starts_with((string)($a['child_object']??''),'cct::')",
        }
        for current,previous in conversions.items():
            self.assertIn(current,restored)
            restored=restored.replace(current,previous)
        self.assertEqual(hashlib.sha256(restored.encode()).hexdigest(),UPSTREAM_RATE_SHA256)
        self.assertNotIn(b'str_starts_with(',raw)
        self.assertIn(b'tour-rates',raw)

        self.assertIn(b'Version: 1.0.5',raw)
        self.assertIn(b'home_impact_preview',raw)
        self.assertIn(b'metadata_changes_public_html',raw)
        self.assertIn(b'wcmlc|mad4b_date_',raw)

    def test_prior_git_delta_retained_in_forward_candidate(self):
        new=(ROOT/REL).read_bytes()
        # Preserve original private-currency/date cookie discrimination.
        self.assertIn(b'wcmlc|mad4b_date_',new)
        self.assertIn(b'home_impact_preview',new)
        self.assertIn(b'metadata_changes_public_html',new)
        self.assertIn(b'membership_change',new)

    def test_networkless_disposable_php_behavior(self):
        args=['docker','run','--rm','--pull=never','--network','none',
          '--read-only','--cap-drop=ALL','--security-opt=no-new-privileges',
          '-v',str(ROOT)+':/work:ro','--entrypoint','php','php:8.5-cli',
          '/work/tests/cache-lifecycle-forward-source-v22.php']
        result=subprocess.run(args,capture_output=True,text=True,timeout=35)
        self.assertEqual(result.returncode,0,result.stderr)
        payload=json.loads(result.stdout)
        self.assertEqual(payload['state'],'PASS')
        self.assertEqual(payload['count'],8)
        self.assertFalse(payload['staging_modified'])
        self.assertFalse(payload['wordpress_database_modified'])

if __name__=='__main__': unittest.main()
