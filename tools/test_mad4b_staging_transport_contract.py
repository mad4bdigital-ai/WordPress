"""Stop-the-line regression for Staging command transport."""
import unittest
from tools.mad4b_staging_transport_contract import validate, CONTRACT


def req(operation="queue_status", **kw):
    value = {"contract": CONTRACT, "operation": operation, "environment": "staging",
             "site": "https://tours.dev.mad4b.com", "database": "etg_commerce_staging",
             "mode": "certified_only", "target_id": "verified-staging",
             "staging_binding_verified": True, "transport_authorized": True}
    value.update(kw)
    return value


class StagingTypedTransportContractTests(unittest.TestCase):
    def test_registry_inventory_requires_no_ssh(self):
        x = validate(req("inventory", target_id=None,
                         staging_binding_verified=False, transport_authorized=False))
        self.assertTrue(x["admitted"])
        self.assertFalse(x["executed"])

    def test_stage_binding_and_permission_required(self):
        for patch, error in [
            ({"target_id": None}, "STAGING_TARGET_NOT_ENROLLED"),
            ({"staging_binding_verified": False}, "STAGING_BINDING_NOT_VERIFIED"),
            ({"transport_authorized": False}, "TRANSPORT_NOT_AUTHORIZED")]:
            with self.subTest(patch=patch):
                x = validate(req(**patch))
                self.assertFalse(x["admitted"])
                self.assertIn(error, x["reasons"])

    def test_never_promote_to_production_or_enforce(self):
        for patch in [{"environment": "production"}, {"mode": "enforce"},
                      {"database": "etg_commerce_production"},
                      {"site": "https://tours.mad4b.com"}]:
            with self.subTest(patch=patch):
                self.assertFalse(validate(req(**patch))["admitted"])

    def test_shell_fields_and_command_injection_rejected(self):
        for patch in [{"command": "date -u"}, {"operation": "shell"},
                      {"target_id": "staging; echo bypass"}]:
            with self.subTest(patch=patch):
                self.assertFalse(validate(req(**patch))["admitted"])

    def test_apply_requires_independent_receipts(self):
        self.assertFalse(validate(req("native_apply"))["admitted"])
        x = validate(req("native_apply", authority_receipt="grant-20261101",
                         rollback_ref="rollback-20261101", correlation_id="operation-20261101",
                         plan_sha256="a"*64, backup_sha256="b"*64,
                         readback_sha256="c"*64))
        self.assertTrue(x["admitted"])
        self.assertFalse(x["execution_authority_granted"])
        self.assertFalse(x["executed"])

    def test_denial_does_not_propose_bypass(self):
        self.assertIn("Do not rephrase or retry", validate(req(transport_authorized=False))["next_step"])

    def test_non_object_rejected(self):
        self.assertFalse(validate(["command"])["admitted"])


if __name__ == "__main__":
    unittest.main()
