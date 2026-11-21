"""CSS worker provenance must be pinned to reviewed Git PHP bytes, never live site state."""
import hashlib
import importlib.util
import shutil
import tempfile
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
PATH=ROOT/'ops/dynamic-critical-css-20261116/run-public-route.py'
spec=importlib.util.spec_from_file_location('mad4b_verified_css_worker',PATH)
worker=importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class SourcePinTests(unittest.TestCase):
    def test_manifest_matches_reviewed_php(self):
        self.assertEqual(len(worker.APPROVED_NATIVE_RUNTIME_SHA256),2)
        for name,sha in worker.APPROVED_NATIVE_RUNTIME_SHA256.items():
            self.assertEqual(hashlib.sha256((ROOT/'wp-content/mu-plugins'/name).read_bytes()).hexdigest(),sha)
        php=(ROOT/'wp-content/mu-plugins/422-mad4b-public-critical-css-runtime.php').read_text()
        self.assertIn('MAD4B_Public_Critical_CSS_Consumer_V2::transform($html,$ctx)',php)
        self.assertIn('MAD4B_Public_Critical_CSS_Consumer_V2::readback($html)',php)
        self.assertIn("header('Cache-Control: private, no-store, max-age=0',true)",php)
        self.assertIn('normalized_escaped_path',php)

    def test_valid_company_allowed_and_tamper_denied(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name)
            p=root/'mu-plugins';p.mkdir()
            for f in worker.APPROVED_NATIVE_RUNTIME_SHA256:
                shutil.copy2(ROOT/'wp-content/mu-plugins'/f,p/f)
            original=worker.CONFIG['wayfa']
            try:
                worker.CONFIG['wayfa']=(original[0],original[1],root,original[3])
                approved=worker.approved_native_runtime_sha('wayfa',root)
                self.assertEqual(approved,worker.APPROVED_NATIVE_RUNTIME_SHA256['422-mad4b-public-critical-css-runtime.php'])
                with self.assertRaisesRegex(ValueError,'COMPANY_ROOT_MISMATCH'):
                    worker.approved_native_runtime_sha('source',root)
                with (p/'422-mad4b-public-critical-css-runtime.php').open('ab') as stream:
                    stream.write(b'\n<?php // deliberate unapproved drift')
                with self.assertRaisesRegex(ValueError,'SOURCE_LIVE_NATIVE_COMPONENT_DRIFT'):
                    worker.approved_native_runtime_sha('wayfa',root)
            finally:
                worker.CONFIG['wayfa']=original

    def test_unsafe_file_is_not_trusted(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name)
            p=root/'mu-plugins';p.mkdir()
            for f in worker.APPROVED_NATIVE_RUNTIME_SHA256:
                shutil.copy2(ROOT/'wp-content/mu-plugins'/f,p/f)
            original=worker.CONFIG['source']
            try:
                worker.CONFIG['source']=(original[0],original[1],root,original[3])
                (p/'421-mad4b-public-critical-css-coverage.php').unlink()
                (p/'421-mad4b-public-critical-css-coverage.php').symlink_to(ROOT/'wp-content/mu-plugins/421-mad4b-public-critical-css-coverage.php')
                with self.assertRaisesRegex(ValueError,'SOURCE_LIVE_NATIVE_COMPONENT_DRIFT'):
                    worker.approved_native_runtime_sha('source',root)
            finally:
                worker.CONFIG['source']=original

    def test_original_worker_not_duplicated(self):
        self.assertNotIn('worktrees/pr814-converged-local-20261115',PATH.read_text())
        self.assertIn('TOOLS=Path(__file__).resolve().parent',PATH.read_text())


if __name__=='__main__':
    unittest.main()
