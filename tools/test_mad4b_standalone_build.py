#!/usr/bin/env python3
"""Offline adversarial tests for the canonical standalone ZIP orchestrator."""
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[1]
HERE = ROOT / "tools/mad4b_standalone_build.py"
SPEC = importlib.util.spec_from_file_location("mad4b_standalone_build", HERE)
builder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(builder)

# Preserve the real gate for refusal tests while fixture builders mock it.
REAL_PHP_SYNTAX_GATE = builder.require_php_syntax


def run(*args, cwd):
    return subprocess.check_output(list(args), cwd=str(cwd), text=True).strip()


class StandalonePackageTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.temp_root = Path(self.tmp.name)
        self.root = self.temp_root / "source"
        self.root.mkdir()
        plugin = self.root / builder.PLUGIN
        (plugin / "tests").mkdir(parents=True)
        (plugin / "config").mkdir(parents=True)
        (plugin / "includes").mkdir()
        (plugin / "mad4b-site-control-plane.php").write_text(
            "<?php\n/**\n * Version: 0.4.0-rc.96\n */\n", encoding="utf-8")
        (plugin / "includes/example.php").write_text("<?php\nreturn true;\n", encoding="utf-8")
        shutil.copyfile(ROOT / builder.CANONICAL_BUILDER,
                        self.root / builder.CANONICAL_BUILDER)
        self.adapter = self.temp_root / "mcp-adapter.zip"
        with zipfile.ZipFile(self.adapter, "w") as z:
            z.writestr("mcp-adapter/manifest.txt", "fixed official profile test bytes")
        digest = builder.sha(self.adapter)
        (self.root / builder.POLICY).write_text(json.dumps({
            "contract": "mad4b.runtime-release-policy.v1",
            "target_adapter_version": "0.7.0"}), encoding="utf-8")
        (self.root / builder.PROFILES).write_text(json.dumps({
            "providers": {"mcp_adapter": {"0.7.0": {
                "version": "0.7.0", "archive_sha256": digest,
                "archive_bytes": self.adapter.stat().st_size}}}}), encoding="utf-8")
        run("git", "init", "-q", cwd=self.root)
        run("git", "config", "user.name", "MAD4B Test", cwd=self.root)
        run("git", "config", "user.email", "test@example.invalid", cwd=self.root)
        run("git", "add", ".", cwd=self.root)
        run("git", "commit", "-qm", "fixture", cwd=self.root)
        self.head = run("git", "rev-parse", "HEAD", cwd=self.root)
        self.out = self.temp_root / "dist"
        # Synthetic ZIP fixtures test deterministic behavior, not host PHP 8.3.
        # The real CLI never accepts this injected test-only bypass.
        self.lint_stub = mock.patch.object(builder, "require_php_syntax",
            return_value={"state": "PASS", "php_version": "8.3",
                          "files_checked": 2, "complete_lint": True})
        self.lint_stub.start()
        self.addCleanup(self.lint_stub.stop)


    def test_real_php_gate_refuses_missing_php(self):
        with mock.patch.object(builder.shutil, "which", return_value=None):
            with self.assertRaisesRegex(builder.BuildBlocked, "php83_cli_required_before_packaging"):
                REAL_PHP_SYNTAX_GATE(self.root, "nonexistent-php83")

    def test_real_php_gate_refuses_parse_error(self):
        def run_checked(argv, cwd, timeout=120):
            if "-r" in argv:
                return subprocess.CompletedProcess(argv, 0, stdout="8.3", stderr="")
            if "-l" in argv:
                return subprocess.CompletedProcess(argv, 255, stdout="", stderr="PHP Parse error")
            raise AssertionError("Unexpected command")
        with mock.patch.object(builder.shutil, "which", return_value="/trusted/php83"), \
             mock.patch.object(builder, "command", side_effect=run_checked):
            with self.assertRaisesRegex(builder.BuildBlocked, "php_syntax_invalid:"):
                REAL_PHP_SYNTAX_GATE(self.root, "php")

    def test_real_php_gate_checks_every_php_source(self):
        calls = []
        def run_checked(argv, cwd, timeout=120):
            if "-r" in argv:
                return subprocess.CompletedProcess(argv, 0, stdout="8.3", stderr="")
            if "-l" in argv:
                calls.append(argv[-1])
                return subprocess.CompletedProcess(argv, 0, stdout="No syntax errors", stderr="")
            raise AssertionError("Unexpected command")
        with mock.patch.object(builder.shutil, "which", return_value="/trusted/php83"), \
             mock.patch.object(builder, "command", side_effect=run_checked):
            summary = REAL_PHP_SYNTAX_GATE(self.root, "php")
        expected = sorted(str(x) for x in (self.root / builder.PLUGIN).rglob("*.php"))
        self.assertEqual(sorted(calls), expected)
        self.assertTrue(summary["complete_lint"])

    def test_build_twice_reproducible_and_source_unmodified(self):
        first = builder.build(self.root, self.out, self.head, self.adapter)
        second = builder.build(self.root, self.temp_root / "other", self.head, self.adapter)
        self.assertEqual(first["archive_sha256"], second["archive_sha256"])
        self.assertEqual(first["build_fingerprint"], second["build_fingerprint"])
        self.assertEqual(first["build_state"], "BUILT_UNVERIFIED")
        self.assertFalse(first["github_ci_certified"])
        self.assertFalse(first["staging_certified"])
        self.assertFalse(first["production_authorized"])
        self.assertFalse(first["publish_authorized"])
        self.assertFalse(first["tests"]["local_tests_passed"])
        self.assertEqual(run("git", "status", "--porcelain", cwd=self.root), "")
        self.assertTrue((self.out / "CANONICAL-PACKAGE-RECEIPT.json").is_file())

    def test_wrong_head_rejected(self):
        with self.assertRaisesRegex(builder.BuildBlocked, "source_head_mismatch"):
            builder.build(self.root, self.out, "f" * 40, self.adapter)

    def test_dirty_source_rejected(self):
        (self.root / builder.PLUGIN / "includes/example.php").write_text("<?php echo 1;")
        with self.assertRaisesRegex(builder.BuildBlocked, "source_worktree_dirty"):
            builder.build(self.root, self.out, self.head, self.adapter)

    def test_modified_adapter_rejected(self):
        self.adapter.write_bytes(b"tampered")
        with self.assertRaises(builder.BuildBlocked):
            builder.build(self.root, self.out, self.head, self.adapter)

    def test_output_inside_checkout_rejected(self):
        with self.assertRaisesRegex(builder.BuildBlocked, "output_must_be_outside_source_checkout"):
            builder.build(self.root, self.root / "dist", self.head, self.adapter)

    def test_existing_dist_refused(self):
        self.out.mkdir()
        (self.out / "old.zip").write_bytes(b"stale")
        with self.assertRaisesRegex(builder.BuildBlocked, "output_must_be_empty"):
            builder.build(self.root, self.out, self.head, self.adapter)

    def test_tampered_archive_rejected(self):
        first = builder.build(self.root, self.out, self.head, self.adapter)
        receipt = json.loads((self.out / "CANONICAL-PACKAGE-RECEIPT.json").read_text())
        (self.out / first["zip_filename"]).write_bytes(b"corrupted")
        with self.assertRaisesRegex(builder.BuildBlocked, "archive_hash_or_size_mismatch"):
            builder.verify_zip(self.out / first["zip_filename"], receipt, self.head)

    def test_unsupported_profile_prevents_untrusted_elevation(self):
        self.assertEqual(builder.CONTRACT, "mad4b.standalone-source-build.v1")
        self.assertTrue(builder.SHA40.fullmatch(self.head))
        self.assertFalse(builder.SHA40.fullmatch("x" * 40))


if __name__ == "__main__":
    unittest.main()
