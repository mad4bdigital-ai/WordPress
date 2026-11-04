#!/usr/bin/env python3
"""Non-authorizing source regression: foreign Context recycling and UI fail-closed behavior.

CI source review only. These checks do not create tickets, modify WordPress
options, or certify deployment. Runtime integration coverage remains separate.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
INCLUDES = ROOT / "includes"


def source(name: str) -> str:
    return (INCLUDES / name).read_text(encoding="utf-8")


class ContextRecycleSourceSafety(unittest.TestCase):
    def test_foreign_recycle_requires_exact_revision_and_inventory_hash(self):
        code = source("class-mad4b-scp-context-authority.php")
        start = code.index("public static function foreign_recycle_apply( array $input )")
        end = code.index("public static function foreign_recycle_archive_status()", start)
        area = code[start:end]
        for expected in (
            "expected_registry_revision", "expected_inventory_sha256",
            "with_registry_lock(", "hash_equals(", "audit_preflight(",
            "mad4b_recycle_plan_stale", "mad4b_recycle_exact_plan_required",
            "ownership_transferred'=>false", "external_files_deleted'=>false",
        ):
            self.assertIn(expected, area, expected)

    def test_archive_status_validates_integrity_before_reporting(self):
        code = source("class-mad4b-scp-context-authority.php")
        start = code.index("public static function foreign_recycle_archive_status()")
        area = code[start:start + 5000]
        for expected in (
            "ARCHIVE_INTEGRITY_FAILURE", "integrity_verified",
            "sources_sha256", "assets_sha256", "hash('sha256',serialize(",
        ):
            self.assertIn(expected, area, expected)

    def test_preflight_label_never_implies_automatic_assignment(self):
        code = source("class-mad4b-scp-context-authority.php")
        self.assertIn("'mad4b/context-source-onboarding-preflight'", code)
        self.assertIn("'automatic_assignment' => false", code)
        self.assertIn("'ownership_transfer_authorized' => false", code)
        self.assertIn("'source_registry_modified' => false", code)

    def test_admin_ui_uses_actual_live_context_status_provider(self):
        code = source("class-mad4b-scp-context-admin-ui.php")
        authority = source("class-mad4b-scp-context-authority.php")
        start = code.index("private static function ownership_lifecycle_snapshot()")
        area = code[start:start + 900]
        self.assertIn("MAD4B_SCP_Context_Authority::status()", area)
        self.assertIn("public static function status()", authority)
        self.assertIn("ownership_reconciliation_lifecycle", authority)
        self.assertIn("foreign_recycle_archive", authority)

    def test_recovery_context_does_not_mint_new_ownership(self):
        code = source("class-mad4b-scp-brand-core-remote-recovery.php")
        # Explicitly forbid any side-effectful migration by review-only diagnostics.
        self.assertIn("class MAD4B_SCP", code)
        self.assertNotIn("ownership_transfer_authorized'=>true", code)
        self.assertNotIn("ownership_transferred'=>true", code)


if __name__ == "__main__":
    unittest.main()
