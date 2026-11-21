"""Preserve current first-party 421/422/V2 owner release and source company fencing."""
import hashlib
import importlib.util
import shutil
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / 'ops/dynamic-critical-css-20261116/run-public-route.py'
spec = importlib.util.spec_from_file_location('mad4b_css_original_worker', WORKER)
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)
TRACKED = ROOT / 'wp-content/mu-plugins'
CURRENT = '13e98efebaef87918d38c1d5be30bd6cf45c9f40c0e3264a31beca79b4663a36'
COVERAGE = 'ae7936c7ad09b059375e1960d4c59144af0b4325933db34bcfa63c26e49233bb'
COMPANION = '249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1'


class ExistingOwnerSourceTest(unittest.TestCase):
    def test_pins_match_original_source(self):
        self.assertEqual(hashlib.sha256((TRACKED/'421-mad4b-public-critical-css-coverage.php').read_bytes()).hexdigest(),COVERAGE)
        self.assertEqual(hashlib.sha256((TRACKED/'422-mad4b-public-critical-css-runtime.php').read_bytes()).hexdigest(),CURRENT)
        self.assertEqual(hashlib.sha256((TRACKED/'422-mad4b-public-critical-css-v2-consumer.php').read_bytes()).hexdigest(),COMPANION)
        code=WORKER.read_text()
        self.assertIn(CURRENT,code)
        self.assertIn(COMPANION,code)
        self.assertIn('MULTI_CSS_V2_RUNTIME_COMPANION_PAIR_DRIFT',code)
        self.assertNotIn('worktrees/pr814-converged-local-20261115/',code)

    def test_original_runtime_and_companion_pair(self):
        with tempfile.TemporaryDirectory() as path:
            root=Path(path); d=root/'mu-plugins';d.mkdir()
            for filename in ('421-mad4b-public-critical-css-coverage.php',
                             '422-mad4b-public-critical-css-runtime.php',
                             '422-mad4b-public-critical-css-v2-consumer.php'):
                shutil.copy2(TRACKED/filename,d/filename)
            original=worker.CONFIG['wayfa']
            worker.CONFIG['wayfa']=(original[0],original[1],root,original[3])
            try:
                sha=worker.approved_native_runtime_sha('wayfa',root)
                self.assertEqual(sha,CURRENT)
                self.assertEqual(worker.approved_v2_consumer_sha('wayfa',root,sha),COMPANION)
                with self.assertRaisesRegex(ValueError,'COMPANY_ROOT_MISMATCH'):
                    worker.approved_native_runtime_sha('source',root)
                with (d/'422-mad4b-public-critical-css-v2-consumer.php').open('ab') as f:
                    f.write(b'\n// unapproved tamper')
                with self.assertRaisesRegex(ValueError,'MULTI_CSS_V2_RUNTIME_COMPANION_PAIR_DRIFT'):
                    worker.approved_native_runtime_sha('wayfa',root)
            finally:
                worker.CONFIG['wayfa']=original

    def test_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as path:
            root=Path(path); d=root/'mu-plugins';d.mkdir()
            for name in ('421-mad4b-public-critical-css-coverage.php',
                         '422-mad4b-public-critical-css-runtime.php',
                         '422-mad4b-public-critical-css-v2-consumer.php'):
                shutil.copy2(TRACKED/name,d/name)
            (d/'421-mad4b-public-critical-css-coverage.php').unlink()
            (d/'421-mad4b-public-critical-css-coverage.php').symlink_to(TRACKED/'421-mad4b-public-critical-css-coverage.php')
            original=worker.CONFIG['source']
            worker.CONFIG['source']=(original[0],original[1],root,original[3])
            try:
                with self.assertRaisesRegex(ValueError,'SOURCE_LIVE_NATIVE_COMPONENT_DRIFT'):
                    worker.approved_native_runtime_sha('source',root)
            finally:
                worker.CONFIG['source']=original

    def test_provisional_css_reverts_to_original_html(self):
        text=(TRACKED/'422-mad4b-public-critical-css-runtime.php').read_text()
        self.assertIn('MAD4B_Public_Critical_CSS_Consumer_V2::transform($html,$ctx)',text)
        self.assertIn('MAD4B_Public_Critical_CSS_Consumer_V2::readback($html)',text)
        self.assertIn("header('Cache-Control: private, no-store, max-age=0',true)",text)
        self.assertIn('normalized_escaped_path',text)


if __name__=='__main__':unittest.main()
