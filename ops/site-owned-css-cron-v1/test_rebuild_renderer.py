#!/usr/bin/env python3
import json,tempfile,unittest
from pathlib import Path
from rebuild_renderer import build,tree_digest,MANIFEST

class RendererRebuild(unittest.TestCase):
    def test_site_renderer_recovers_exact_bytes(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-renderer-rebuild-') as tmp:
            dest=Path(tmp)/'renderer'
            build(dest)
            records=json.loads(MANIFEST.read_text())
            self.assertEqual(len(records['files']),119)
            self.assertEqual(tree_digest(dest),records['tree_sha256'])
            self.assertIn('MAD4B_CSS_PROBE_ONLY',(dest/'run-site.sh').read_text())
            self.assertFalse(any(p.is_symlink() for p in dest.rglob('*')))

    def test_existing_target_is_not_overwritten(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-renderer-refuse-') as tmp:
            with self.assertRaises(ValueError):
                build(Path(tmp))

    def test_manifest_contract_and_tree_pin(self):
        data=json.loads(MANIFEST.read_text())
        self.assertEqual(data['contract'],'mad4b.css.site-owned-renderer.v1')
        self.assertEqual(len(data['files']),119)
        self.assertEqual(data['tree_sha256'],
            '33951bee57e041b6dc572f4f22abdf84936a1e5d816c7b06686484503e5fe6a0')

if __name__=='__main__':
    unittest.main(verbosity=2)
