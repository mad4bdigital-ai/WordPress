#!/usr/bin/env python3
"""CI-only, negative-path source review of the Original305 and Native Source read bridges."""
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
PLUG = ROOT / "wp-content/plugins/mad4b-site-control-plane"
BOOT = PLUG / "mad4b-site-control-plane.php"
SOURCE_REVIEW = PLUG / "includes/class-mad4b-scp-native-source-release-review.php"
JOB_READ = PLUG / "includes/class-mad4b-scp-original-job-readback.php"


class NativeSourceReadOnlyReview(unittest.TestCase):
    def test_only_existing_bootstrap_registers_read_bounded_modules(self):
        data = BOOT.read_text(encoding="utf-8")
        for line in (
            "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-source-release-review.php';",
            "MAD4B_SCP_Native_Source_Release_Review::boot();",
            "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-original-job-readback.php';",
            "MAD4B_SCP_Original_Job_Readback::boot();",
        ):
            self.assertEqual(data.count(line), 1, line)
        self.assertLess(data.index("MAD4B_SCP_Operation_Registry::boot();"),
                        data.index("MAD4B_SCP_Native_Source_Release_Review::boot();"))

    def test_source_catalog_provides_no_implicit_write_permission(self):
        data = SOURCE_REVIEW.read_text(encoding="utf-8")
        for required in ("'publisher_authorized'=>false", "'authorization_created'=>false",
                         "'native_manifest_verified_by_wp'=>false", "'readonly'=>true"):
            self.assertIn(required, data)
        for forbidden in ("wp_insert_post(", "update_option(", "delete_option(",
                          "shell_exec(", "file_put_contents(", "wp_register_ability( self::MUTATION_ABILITY"):
            self.assertNotIn(forbidden, data)
        self.assertNotIn("source.code.release", data)

    def test_original_job_claim_readback_remains_not_a_signer(self):
        data = JOB_READ.read_text(encoding="utf-8")
        for required in ("'readonly'=>true", "'destructive'=>false",
                         "'original312_eligible'=>false", "'signed_original313_receipt_verified'=>false",
                         "'job_or_claim_mutated'=>false"):
            self.assertIn(required, data)
        for forbidden in ("wp_insert_post(", "update_option(", "delete_option(",
                          "add_option(", "shell_exec(", "wp_remote_post("):
            self.assertNotIn(forbidden, data)
        self.assertNotIn("source.code.release", data)

    def test_source_modules_do_not_register_under_unrelated_write_paths(self):
        files = (PLUG / "includes/class-mad4b-scp-plugin.php",
                 PLUG / "includes/class-mad4b-scp-server-core-catalog.php")
        for source in files:
            data = source.read_text(encoding="utf-8")
            self.assertNotIn("MAD4B_SCP_Native_Source_Release_Review", data)
            self.assertNotIn("MAD4B_SCP_Original_Job_Readback", data)


if __name__ == "__main__":
    unittest.main()
