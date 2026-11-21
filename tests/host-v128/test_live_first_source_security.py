"""Source-first publication cannot silently become a generic write API."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
ENGINE=(ROOT/"ops/host-authority-v128/live_first_reconciliation.py").read_text()
DOC=(ROOT/"docs/host-v128/live-first-reconciliation-v129.md").read_text()

class ReconciliationSourceSafetyTests(unittest.TestCase):
    def test_no_automatic_host_operations_or_signing_key_load(self):
        for token in ("subprocess.run(", "os.system(", "paramiko.",
                      "update_option(", "wp_ajax_", "register_rest_route(",
                      "from_private_bytes(", "load_pem_private_key(",
                      "ssh -i ", "wp-cli apply"):
            with self.subTest(token=token):
                self.assertNotIn(token,ENGINE)

    def test_desired_requires_independent_signed_highwater(self):
        self.assertIn("keys_independent(owner_public,registry_public)",ENGINE)
        self.assertIn("DESIRED_REGISTRY_NOT_CURRENT",ENGINE)
        self.assertIn("DESIRED_SITE_BINDING_MISMATCH",ENGINE)
        self.assertIn("DESIRED_SIGNATURE_INVALID",ENGINE)

    def test_previous_cannot_be_plain_plan_placeholder(self):
        self.assertIn("SEALED_AND_HASH_VERIFIED",ENGINE)
        self.assertIn("host_private_rollback_store",ENGINE)
        self.assertIn("PREVIOUS_BACKUP_SIGNATURE_INVALID",ENGINE)
        self.assertIn("AWAITING_INDEPENDENT_BACKUP",ENGINE)

    def test_live_precommit_compare_and_swap_required(self):
        self.assertIn("PRECOMMIT_LIVE_CURRENT_CHANGED_OR_NOT_FRESH",ENGINE)
        self.assertIn("executor_must_recheck_live_preimage_at_commit",ENGINE)
        self.assertIn("exact_owner_or_operator_signed_grant_required",ENGINE)
        self.assertIn('"operation_executed":False',ENGINE)

    def test_deployment_capability_not_silently_registered(self):
        self.assertIn("required\nfuture executor contract",DOC)
        self.assertIn("not a registered or live enabled ability",DOC)
        self.assertIn("PR merge == WordPress activation",DOC)

if __name__=="__main__":unittest.main()
