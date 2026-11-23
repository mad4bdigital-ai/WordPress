#!/usr/bin/env python3
"""Regression contract: all Control Plane release ZIP lanes accept at most 24 MiB.

This does not grant an approval, bypass exact SHA checks, or expand unrelated
media/attachment import limits.
"""
from __future__ import annotations

import ast
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAX_PACKAGE = 24 * 1024 * 1024
LIVE_SOURCE_PACKAGE_BYTES = 18_850_579

PYTHON_LANES = {
    "tools/mad4b_standalone_build.py": [
        "MAX_STAGING_PACKAGE_BYTES = 24 * 1024 * 1024",
        "archive.stat().st_size <= MAX_STAGING_PACKAGE_BYTES",
    ],
    "tools/mad4b_standalone_evidence.py": [
        "zip_path.stat().st_size <= build.MAX_STAGING_PACKAGE_BYTES",
    ],
    "tools/mad4b_standalone_runner_receipt.py": [
        "archive.stat().st_size <= 24 * 1024 * 1024",
    ],
    "tools/mad4b_publish_ci_staging_candidate.py": [
        "0 < len(b) <= 24*1024*1024 and sha(b) == expected_digest",
    ],
    "tools/mad4b_publish_offline_staging_candidate.py": [
        "zipsize <= 0 or zipsize > 24 * 1024 * 1024",
    ],
}

PHP_LANES = {
    "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-wp-native-build.php": {
        "MAX_ZIP_BYTES": MAX_PACKAGE,
        "MAX_SOURCE_BYTES": MAX_PACKAGE,
    },
    "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-selected-head-update.php": {
        "MAX_ZIP_BYTES": MAX_PACKAGE,
    },
    "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-self-update.php": {
        "MAX_UPLOAD_BYTES": MAX_PACKAGE,
    },
}


class PackageSizeBudgetContract(unittest.TestCase):
    def test_observed_18_85_mb_archive_is_inside_budget(self) -> None:
        self.assertGreater(LIVE_SOURCE_PACKAGE_BYTES, 16 * 1024 * 1024)
        self.assertLessEqual(LIVE_SOURCE_PACKAGE_BYTES, MAX_PACKAGE)

    def test_boundary_is_still_bounded(self) -> None:
        allowed = lambda n: 0 < n <= MAX_PACKAGE
        self.assertFalse(allowed(0))
        self.assertTrue(allowed(MAX_PACKAGE))
        self.assertFalse(allowed(MAX_PACKAGE + 1))
        self.assertFalse(allowed(32 * 1024 * 1024))

    def test_all_python_release_lanes_agree(self) -> None:
        for path, fragments in PYTHON_LANES.items():
            with self.subTest(file=path):
                body = (ROOT / path).read_text(encoding="utf-8")
                ast.parse(body, filename=path)
                for fragment in fragments:
                    self.assertEqual(body.count(fragment), 1, (path, fragment))

    def test_all_php_release_lanes_agree(self) -> None:
        for path, expected in PHP_LANES.items():
            with self.subTest(file=path):
                body = (ROOT / path).read_text(encoding="utf-8")
                for name, value in expected.items():
                    matches = re.findall(r"const\\s+" + re.escape(name) +
                                         r"\\s*=\\s*(\\d+)\\s*;", body)
                    self.assertEqual(matches, [str(value)], (path, name))

    def test_integrity_and_approval_requirements_remain(self) -> None:
        checks = {
            "tools/mad4b_standalone_build.py": "verify_zip(archive, receipt, source)",
            "tools/mad4b_publish_ci_staging_candidate.py": "sha(b) == expected_digest",
            "tools/mad4b_publish_offline_staging_candidate.py": "signer_private_key_pem",
            "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-selected-head-update.php": "hash_equals",
            "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-self-update.php": "MAX_UPLOAD_BYTES",
        }
        for path, fragment in checks.items():
            with self.subTest(file=path):
                self.assertIn(fragment, (ROOT / path).read_text(encoding="utf-8"))

    def test_unrelated_attachment_limit_not_relaxed(self) -> None:
        attachment = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-chatgpt-file-transfer.php"
        self.assertIn("const MAX_BYTES = 16777216;", attachment.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
