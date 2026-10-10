#!/usr/bin/env python3
"""Security and classification regressions for three preview evidence lanes."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("mad4b_preview_matrix", ROOT / "mad4b-preview-matrix.py")
preview = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preview)
SHA = "0b52e7995f1b317b8947f27083f1c0ab4ce58959"
SITE = "https://staging.allroyalegypt.com"


class MatrixContracts(unittest.TestCase):
    def test_safe_origin_only(self):
        self.assertEqual(preview.origin(SITE), SITE)
        for value in ("http://staging.allroyalegypt.com",
                      "https://staging.allroyalegypt.com:443",
                      "https://user:pass@staging.allroyalegypt.com",
                      "https://staging.allroyalegypt.com/?token=abc",
                      "https://127.0.0.1", "https://localhost"):
            with self.subTest(origin=value), self.assertRaises(ValueError):
                preview.origin(value)

    def test_paths_fail_closed(self):
        for value in ("//evil.example/", "/../wp-admin", "/wp-admin?x=1",
                      "/%2e%2e/", "/hello#bad", "/wp-admin\\test"):
            with self.subTest(path=value), self.assertRaises(ValueError):
                preview.checked_path(value)
        self.assertEqual(preview.checked_path("/egypt-tours/"), "/egypt-tours/")

    def test_exact_queue_probe_binding(self):
        good = SITE + "/?mad4b_frontend_probe=7d4713c7-94e1-4d07-a8be-978262f8ff27"
        self.assertEqual(preview.checked_probe_url(good, SITE), good)
        for value in (good + "&debug=1", good.replace(SITE, "https://attacker.example"),
                      SITE + "/?mad4b_frontend_probe=garbage",
                      SITE + "/?mad4b_frontend_probe=7d4713c7-94e1-4d07-a8be-978262f8ff27#x"):
            with self.subTest(url=value), self.assertRaises(ValueError):
                preview.checked_probe_url(value, SITE)

    def test_foreign_auth_never_accepted(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / "auth.json"
            file.write_text(json.dumps({"cookies": [{"domain": ".example.com"}],
                                        "origins": []}), encoding="utf-8")
            with self.assertRaises(ValueError):
                preview.checked_auth_state(str(file), SITE)
            file.write_text(json.dumps({"cookies": [{"domain": ".allroyalegypt.com"}],
                                        "origins": []}), encoding="utf-8")
            with self.assertRaises(ValueError):
                preview.checked_auth_state(str(file), SITE)
            file.write_text(json.dumps({"cookies": [{"domain": "staging.allroyalegypt.com"}],
                                        "origins": []}), encoding="utf-8")
            preview.checked_auth_state(str(file), SITE)

    def test_evidence_is_never_signing_or_promotion(self):
        summary = preview.report_summary([
            {"mode": "browser", "http_status": 200},
            {"mode": "customizer", "iframe_same_origin": True},
            {"mode": "native", "native_state": "observed"},
        ])
        self.assertTrue(summary["browser_http_200"])
        self.assertTrue(summary["customizer_iframe_same_origin"])
        self.assertTrue(summary["native_cli_observed"])
        self.assertFalse(summary["release_certified"])
        self.assertFalse(summary["external_signed_receipt"])
        self.assertFalse(summary["production_unchanged_proven"])

    def test_cli_enforces_bounds(self):
        for extra in (["--samples", "99"], ["--paths", "/../escape"],
                      ["--timeout-ms", "3"], ["--expected-source-sha", "x"]):
            with self.subTest(extra=extra), self.assertRaises((SystemExit, ValueError)):
                preview.parse_args(["--origin", SITE, "--expected-source-sha", SHA,
                                    "--output", "report.json"] + extra)

    def test_native_requires_actual_wordpress_root(self):
        args = types.SimpleNamespace(wp_root="/nonexistent/staging/site", expected_source_sha=SHA,
                                     wp_cli="wp", native_rest=False)
        with self.assertRaisesRegex(ValueError, "WORDPRESS_ROOT_MISSING"):
            preview.native_observation(args, SITE)

    def test_native_exact_identity_rejects_stale_provider(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "wp-config.php").write_text("<?php", encoding="utf-8")
            args = types.SimpleNamespace(wp_root=str(root), expected_source_sha=SHA,
                                         wp_cli="wp", native_rest=False)
            reported = {
                "contract": "mad4b.wp-native-preview-evidence.v1",
                "source_commit_sha": "a" * 40,
                "server_elapsed_ms": 22, "db_queries": 3,
                "peak_memory_bytes": 1024, "theme_slug": "astra",
                "theme_mod_count": 2, "rest": {"state": "not_requested"},
            }
            result = subprocess.CompletedProcess(args=[], returncode=0, stdout=json.dumps(reported), stderr="")
            with mock.patch.object(preview.subprocess, "run", return_value=result):
                with self.assertRaisesRegex(RuntimeError, "NATIVE_IDENTITY_MISMATCH"):
                    preview.native_observation(args, SITE)

    def test_native_current_identity_is_only_diagnostic(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "wp-config.php").write_text("<?php", encoding="utf-8")
            args = types.SimpleNamespace(wp_root=str(root), expected_source_sha=SHA,
                                         wp_cli="wp", native_rest=False)
            reported = {
                "contract": "mad4b.wp-native-preview-evidence.v1",
                "source_commit_sha": SHA,
                "server_elapsed_ms": 22, "db_queries": 3,
                "peak_memory_bytes": 1024, "theme_slug": "astra",
                "theme_mod_count": 2, "rest": {"state": "not_requested"},
            }
            result = subprocess.CompletedProcess(args=[], returncode=0, stdout=json.dumps(reported), stderr="")
            with mock.patch.object(preview.subprocess, "run", return_value=result):
                row = preview.native_observation(args, SITE)[0]
            self.assertFalse(row["accepted_as_frontend_http"])
            self.assertFalse(row["accepted_as_external_signed_receipt"])


if __name__ == "__main__":
    unittest.main()
