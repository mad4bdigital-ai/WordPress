"""Non-mutating exact-scope routing tests for Staging and Production.

Original Core permissions are mocked only inside these unit tests.
Production authorization and identity enrollment are *not* simulated as real.
"""
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("router_probe", pathlib.Path(__file__).with_name("router.py"))
router = importlib.util.module_from_spec(spec)
spec.loader.exec_module(router)

UUID = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"

def sample_policy(target, environment):
    return {
        "allowed_read_operations": ["wordpress.siteurl.read"],
        "targets": {
            target: {
                "site_uuid": UUID,
                "canonical_origin": "https://sample.example",
                "environment": environment,
                "providers": {
                    "cross_ssh_wpcli": {
                        "registered_target": "sample:" + environment,
                        "expected_root": "/home/x/public_html",
                    }
                },
                "preferences": ["cross_ssh_wpcli"],
            }
        },
    }

class TestExactScopeReadOnlyRouting(unittest.TestCase):
    def test_production_route_supported_after_exact_identity_and_authority(self):
        with patch.object(router, "registered_target", return_value=True), patch.object(
            router, "authority", return_value="VERIFIED"
        ):
            result = router.plan(
                "sample:production", sample_policy("sample:production", "production"),
                lambda *args: "READY"
            )
        self.assertEqual(result["state"], "READY")
        self.assertEqual(result["environment"], "production")
        self.assertEqual(result["selected_lane"], "cross_ssh_wpcli")
        self.assertIs(result["production_mutation"], False)

    def test_other_company_cannot_reuse_existing_binding(self):
        result = router.plan(
            "other:production", sample_policy("sample:production", "production")
        )
        self.assertEqual(result["reason"], "TARGET_NOT_ENROLLED")

    def test_site_binding_mismatch_denies_before_probe(self):
        with patch.object(router, "registered_target", return_value=False):
            result = router.plan(
                "sample:production", sample_policy("sample:production", "production"),
                lambda *args: self.fail("No probe permitted after a scope mismatch")
            )
        self.assertEqual(result["reason"], "SCOPE_MISMATCH")

    def test_missing_original_authority_denies_before_probe(self):
        with patch.object(router, "registered_target", return_value=True), patch.object(
            router, "authority", return_value="AUTHORITY_DENIED"
        ):
            result = router.plan(
                "sample:production", sample_policy("sample:production", "production"),
                lambda *args: self.fail("No probe permitted without original authority")
            )
        self.assertEqual(result["reason"], "AUTHORITY_DENIED")

    def test_non_allowlisted_write_operation_denied(self):
        result = router.plan(
            "sample:production", sample_policy("sample:production", "production"),
            operation="wordpress.option.write"
        )
        self.assertEqual(result["reason"], "OPERATION_NOT_ALLOWLISTED")

if __name__ == "__main__":
    unittest.main()
