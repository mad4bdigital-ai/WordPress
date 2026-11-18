#!/usr/bin/env python3
"""Hermetic nonpublishing tests for live/Git parity protection."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("live_git_parity_check", HERE / "check.py")
observer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(observer)


class ParityTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="mad4b-parity-test-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)

    def test_snapshot_is_immutable(self):
        path = self.root / "private" / "asset.sha"
        observer.save_once(path, b"live-version-1")
        observer.save_once(path, b"live-version-1")
        with self.assertRaisesRegex(ValueError, "SNAPSHOT_CONTENT_COLLISION"):
            observer.save_once(path, b"live-version-2")
        self.assertEqual(path.read_bytes(), b"live-version-1")

    def test_live_path_validation_and_change_detection(self):
        root = self.root / "wordpress"
        file = root / "wp-content/mu-plugins/mad4b-sample.php"
        file.parent.mkdir(parents=True)
        file.write_bytes(b"<?php echo 1;\n")
        before, _ = observer.inspect_live(root, "wp-content/mu-plugins/mad4b-sample.php")
        self.assertEqual(before, b"<?php echo 1;\n")
        file.write_bytes(b"<?php echo 2;\n")
        after, _ = observer.inspect_live(root, "wp-content/mu-plugins/mad4b-sample.php")
        self.assertNotEqual(observer.digest(before), observer.digest(after))
        with self.assertRaisesRegex(ValueError, "INVALID_POLICY_RELATIVE_PATH"):
            observer.inspect_live(root, "wp-content/../wp-config.php")
        outside = root / "wp-content/mu-plugins/linked.php"
        outside.symlink_to(file)
        with self.assertRaisesRegex(ValueError, "LIVE_PATH_SYMLINK"):
            observer.inspect_live(root, "wp-content/mu-plugins/linked.php")

    def test_git_reads_pinned_commit_not_worktree(self):
        repo = self.root / "git"
        repo.mkdir()
        subprocess.run(["git", "init", "-q", str(repo)], check=True)
        asset = repo / "wp-content/mu-plugins/mad4b-test.php"
        asset.parent.mkdir(parents=True)
        asset.write_bytes(b"REVIEWED")
        subprocess.run(["git", "-C", str(repo), "add", "."], check=True)
        subprocess.run(["git", "-C", str(repo), "-c", "user.name=Test",
                        "-c", "user.email=fixture@example.invalid",
                        "commit", "-qm", "fixture"], check=True)
        sha = subprocess.check_output(["git", "-C", str(repo), "rev-parse", "HEAD"],
                                      text=True).strip()
        asset.write_bytes(b"UNREVIEWED")
        self.assertEqual(observer.git_blob(repo, sha, "wp-content/mu-plugins/mad4b-test.php"),
                         b"REVIEWED")
        self.assertIsNone(observer.git_blob(repo, sha, "wp-content/mu-plugins/nonexistent.php"))


if __name__ == "__main__":
    unittest.main()
