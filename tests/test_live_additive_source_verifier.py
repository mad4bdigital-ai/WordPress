"""Static source-only acceptance for the 19-file additive portfolio provenance."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/"tools/verify_live_additive_source.py"
spec=importlib.util.spec_from_file_location("source_addendum_verifier",SOURCE)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
MANIFEST=json.loads(mod.MANIFEST.read_text())

class VerifyAddendum(unittest.TestCase):
    def test_sources_match_and_no_release(self):
        r=mod.verify(MANIFEST,live=False)
        self.assertEqual(r['verified_files'],19)
        self.assertFalse(r['release_certified'])
    def test_live_source_parity(self):
        r=mod.verify(MANIFEST,live=True)
        self.assertTrue(r['verified_against_current_live'])
    def test_reject_overwrite(self):
        m=copy.deepcopy(MANIFEST)
        m['files'][0]['path']="wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
        with self.assertRaises(mod.Unverified):mod.verify(m,live=False)
    def test_reject_forged_digest(self):
        m=copy.deepcopy(MANIFEST)
        m['files'][0]['sha256']="0"*64
        with self.assertRaises(mod.Unverified):mod.verify(m,live=False)
    def test_reject_duplicate(self):
        m=copy.deepcopy(MANIFEST)
        m['files'][1]['path']=m['files'][0]['path']
        with self.assertRaises(mod.Unverified):mod.verify(m,live=False)
    def test_reject_unsafe_path(self):
        m=copy.deepcopy(MANIFEST)
        m['files'][0]['path']="../escape"
        with self.assertRaises(mod.Unverified):mod.verify(m,live=False)

if __name__=="__main__":unittest.main()
