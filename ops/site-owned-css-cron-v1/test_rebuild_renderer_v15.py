#!/usr/bin/env python3
import json,tempfile,unittest
from pathlib import Path
from rebuild_renderer_v15 import build,tree_digest,MANIFEST

class RendererRebuild(unittest.TestCase):
    def test_site_renderer_recovers_exact_bytes(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-renderer-rebuild-') as tmp:
            dest=Path(tmp)/'renderer'
            build(dest)
            records=json.loads(MANIFEST.read_text())
            self.assertEqual(len(records['files']),124)
            self.assertEqual(tree_digest(dest),records['tree_sha256'])
            self.assertTrue((dest/'public-css-manifest-observer.py').is_file())
            self.assertTrue((dest/'public-css-dependency-manifest.py').is_file())
            self.assertTrue((dest/'tools/verify-ordered-css.js').is_file())
            self.assertIn('public-css-manifest-observer.py',(dest/'run-public-route.py').read_text())
            self.assertEqual((dest/'run-public-route.py').read_bytes(),
                             (MANIFEST.parent.parent/'dynamic-critical-css-20261116/run-public-route.py').read_bytes())
            self.assertIn('multi_css_route_qa_pending',(dest/'public-route-queue.py').read_text())
            self.assertIn('MAD4B_CSS_PROBE_ONLY',(dest/'run-site.sh').read_text())
            self.assertFalse(any(p.is_symlink() for p in dest.rglob('*')))
            import re
            site_src=(dest/'run-site.sh').read_text()
            pins=re.findall(r'^   CONSUMER_SHA=([0-9a-f]+)$',site_src,re.M)
            self.assertEqual(len(pins),2)
            self.assertTrue(all(len(pin)==64 for pin in pins))
            live_expected='60fc91116affa5faf539cc835e12ce3939d598be68c94a56b21a36f860c5f29e'
            self.assertEqual(pins[1],live_expected)

    def test_existing_target_is_not_overwritten(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-renderer-refuse-') as tmp:
            with self.assertRaises(ValueError):
                build(Path(tmp))

    def test_manifest_contract_and_tree_pin(self):
        data=json.loads(MANIFEST.read_text())
        self.assertEqual(data['contract'],'mad4b.css.site-owned-renderer.v1')
        self.assertEqual(len(data['files']),124)
        self.assertEqual(data['tree_sha256'],
            '79f2ee6db6cf575136164a20945bb55a6a953afe1e3f5967c8e81f441fe98f71')

if __name__=='__main__':
    unittest.main(verbosity=2)
