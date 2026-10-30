import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

PKG = Path(__file__).resolve().parents[1]
DEPLOY = PKG / "tools" / "deploy_reviewed_mu.py"


def run(*args):
    return subprocess.run([sys.executable, str(DEPLOY), *map(str, args)],
                          text=True, capture_output=True, env=dict(os.environ))


class MuSourceTest(unittest.TestCase):
    def test_manifest_complete(self):
        p = run("--check")
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertEqual(json.loads(p.stdout)["files"], 32)

    def test_dry_run_and_unreviewed_drift(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "wp-settings.php").write_text("<?php")
            dst = root / "wp-content" / "mu-plugins"
            dst.mkdir(parents=True)
            for p in (PKG / "mu-plugins").glob("*.php"):
                shutil.copy2(p, dst / p.name)
            result = run("--dry-run", "--target", dst)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout)["already_current"], 32)
            (dst / "shared-extra-display-mode.php").write_bytes(b"unreviewed")
            result = run("--dry-run", "--target", dst)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("unreviewed_target_drift", result.stderr)

    def test_symlink_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "wp-settings.php").write_text("<?php")
            dst = root / "wp-content" / "mu-plugins"
            dst.mkdir(parents=True)
            for p in (PKG / "mu-plugins").glob("*.php"):
                shutil.copy2(p, dst / p.name)
            p = dst / "shared-extra-display-mode.php"
            p.unlink()
            p.symlink_to(PKG / "mu-plugins" / p.name)
            result = run("--dry-run", "--target", dst)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("missing_or_symlink_target", result.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
