"""Regressions against unnecessary MAD4B execution denials."""
import unittest
from tools.mad4b_staging_transport_contract import diagnose, validate


class StagingTypedTransportContractTests(unittest.TestCase):
    def test_shared_production_host_is_not_a_staging_ban(self):
        r = diagnose({"operation": "queue_status", "site_environment": "staging",
                      "host_inventory_environment": "production"})
        self.assertFalse(r["staging_only_target_required"])
        self.assertTrue(any("MAY_DIFFER" in x for x in r["notes"]))
        self.assertFalse(r["local_permission_gate_added"])

    def test_authorized_production_is_not_rejected_by_helper(self):
        r = diagnose({"operation": "write", "site_environment": "production", "mode": "enforce"})
        self.assertFalse(r["arbitrary_runtime_mode_ban"])
        self.assertEqual(r["permission_decision"], "ORIGINAL_AUTHORITY_ONLY")
        self.assertEqual(r["risk_class"], "may_mutate")

    def test_reads_do_not_require_backup_or_staging_inventory_entry(self):
        for op in ("read", "queue_status", "source_readback", "inventory"):
            with self.subTest(operation=op):
                r = diagnose({"operation": op, "target_id": None})
                self.assertFalse(r["backup_required_for_read"])
                self.assertFalse(r["staging_only_target_required"])
                self.assertFalse(r["local_permission_gate_added"])

    def test_missing_approval_flag_is_not_an_automatic_denial(self):
        r = diagnose({"operation": "native_apply", "transport_authorized": False})
        self.assertEqual(r["permission_decision"], "ORIGINAL_AUTHORITY_ONLY")
        self.assertFalse(r["local_permission_gate_added"])
        self.assertFalse(r["executed"])

    def test_original_shell_route_not_unconditionally_banned(self):
        r = diagnose({"operation": "shell", "command": "date -u"})
        self.assertEqual(r["risk_class"], "may_mutate")
        self.assertEqual(r["permission_decision"], "ORIGINAL_AUTHORITY_ONLY")

    def test_external_safety_refusal_not_misdiagnosed_as_bash(self):
        r = diagnose({"operation": "shell",
                      "observed_error": "This tool call was blocked by OpenAI's safety checks"})
        self.assertEqual(r["failure_layer"], "TOOL_ACCESS_DENIED_PRE_EXECUTION")
        self.assertIn("DO_NOT_RETRY_THE_REJECTED_ACTION_TO_EVADE_SAFETY_CHECKS", r["notes"])

    def test_actual_shell_exit_is_separate_from_tool_refusal(self):
        r = diagnose({"operation": "shell", "observed_shell_exit_code": 2})
        self.assertEqual(r["failure_layer"], "REMOTE_SHELL_EXIT_OBSERVED")

    def test_unknown_operation_deferred_to_original_catalog(self):
        r = validate({"operation": "new_provider_operation"})
        self.assertEqual(r["risk_class"], "refer_to_original_capability_metadata")
        self.assertFalse(r["local_permission_gate_added"])

    def test_not_an_approval_or_execution_mechanism(self):
        r = diagnose({"operation": "native_apply", "authority_receipt": "anything"})
        self.assertFalse(r["executed"])
        self.assertEqual(r["permission_decision"], "ORIGINAL_AUTHORITY_ONLY")

    def test_bad_input_is_diagnostic_not_authorization(self):
        self.assertEqual(diagnose(["bad"])["diagnostic"], "INVALID_INPUT")
        self.assertEqual(diagnose(["bad"])["permission_decision"], "ORIGINAL_AUTHORITY_ONLY")


if __name__ == "__main__":
    unittest.main()
