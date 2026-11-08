"""Source-only fail-closed contract tests for administrator two-phase reissue.

This test never loads WordPress, accesses a database or issues a Job.
Run with: python3 -m unittest discover -v -s tests -p test_source_reissue_two_phase.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
HANDLER = (ROOT / "class-mad4b-lifecycle-source-reissue-admin.php").read_text()
BOOT = (ROOT / "mad4b-lifecycle-orchestrator.php").read_text()


class OriginalSourceReissueTests(unittest.TestCase):
    def test_new_batch_before_old_cancel(self):
        self.assertLess(HANDLER.index("'action'=>'PAUSE'"),
                        HANDLER.index("MAD4B_Hub_Bulk_Jobs_Queue::enqueue"))
        self.assertLess(HANDLER.index("MAD4B_Hub_Bulk_Jobs_Queue::enqueue"),
                        HANDLER.index("'action'=>'CANCEL'"))

    def test_fail_closed_no_old_source_resume(self):
        self.assertNotIn("'action'=>'RESUME'", HANDLER)
        self.assertIn("old remains PAUSED", HANDLER)
        self.assertIn("manual reconciliation required", HANDLER.lower())
        self.assertIn("New batch exists; old remains PAUSED or uncertain", HANDLER)

    def test_source_owned_permissions_and_exact_nonce(self):
        for marker in (
            "MAD4B_Lifecycle_Orchestrator::can_write()",
            "MAD4B_Content_Hub::permission()",
            "current_user_can('activate_plugins')",
            "check_admin_referer('mad4b_lifecycle_reissue_'.$batch)",
            "($_SERVER['REQUEST_METHOD']??'')!=='POST'",
            "hash_equals($p['source_preimage_sha256'],$expected)",
            "MAD4B_Hub_Original_Context_Evidence::bind_batch",
            "MAD4B_Hub_Governed_Single_Flight::plan",
            "original_single_flight_receipt_missing",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, HANDLER)

    def test_exact_new_and_old_receipt_readback(self):
        for marker in (
            "($oldBatch['state']??'')!=='PAUSED'",
            "($confirmed['state']??'')!=='ACTIVE'",
            "($confirmed['batch_key']??'')!==$p['new_batch_key']",
            "($t['assigned_executor']??'')==='vps_runner'",
            "($t['assigned_executor']??'')==='mcp_client'",
            "($oldFinal['state']??'')!=='CANCELLED'",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, HANDLER)

    def test_no_direct_db_writes_or_forged_313(self):
        for prohibited in (
            "$wpdb->insert(", "$wpdb->update(", "$wpdb->query('START TRANSACTION')",
            "wp_insert_post(", "wp_update_post(", "shell_exec(", "system(",
            "'native_313_certified'=>true",
        ):
            self.assertNotIn(prohibited, HANDLER)
        self.assertIn("'native_313_certified'=>false", HANDLER)

    def test_staging_only_bootstrap(self):
        self.assertIn("class-mad4b-lifecycle-source-reissue-admin.php", BOOT)
        self.assertIn("['MAD4B_Lifecycle_Source_Reissue_Admin','boot'],122", BOOT)
        self.assertIn("MAD4B_Lifecycle_Orchestrator::scoped()", HANDLER)
        self.assertIn("add_action('admin_post_mad4b_lifecycle_source_reissue'", HANDLER)


if __name__ == "__main__":
    unittest.main()
