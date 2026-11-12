"""An unsafe source candidate must be held even with 100% focused unit tests."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "ops/native-source-publisher-v1/audit_release_readiness.py"
spec = importlib.util.spec_from_file_location("mad4b_source_release_audit", MODULE)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class NativeSourceAudit(unittest.TestCase):
    def test_security_probes_are_temp_git_and_never_install(self):
        findings = audit.candidate_probes()
        self.assertEqual(findings["test_scope"], "EPHEMERAL_GIT_FIXTURES_ONLY")
        self.assertFalse(findings["site_files_touched"])
        for key in ("different_git_source_for_same_target", "changed_count_mismatch"):
            self.assertIn(findings[key], {"ACCEPTED", "DENIED"})

    def test_gate_blocks_unenrolled_publisher(self):
        result = audit.evaluate()
        self.assertEqual(result["state"], "HOLD")
        self.assertFalse(result["authorization_issued"])
        self.assertFalse(result["live_deployment_performed"])
        self.assertFalse(result["first_party_signed_original313_receipt_verified"])
        self.assertIn("FIRST_PARTY_SOURCE_PROVIDER_NOT_ENROLLED", result["blockers"])
        self.assertIn("ROOT_OWNED_NATIVE_POLICY_NOT_ENROLLED", result["blockers"])
        self.assertEqual(
            result["installed_status"]["unregistered_candidate_operations"],
            result["installed_status"]["candidate_operations"],
        )


    def test_new_catalog_operation_is_discovered_without_code_edits(self):
        with tempfile.TemporaryDirectory(prefix="source-catalog-audit-") as temp:
            folder = Path(temp)
            generated = folder / "catalog/generated"
            generated.mkdir(parents=True)
            for op in ("alpha.source.release", "beta.source.release"):
                (generated / (op + ".manifest.json")).write_text(
                    json.dumps({"operation_id": op, "provider": op + ".provider"})
                )
            index = folder / "installed-index.json"
            index.write_text(json.dumps({
                "providers": [{
                    "manifest": "dynamic-operation-registry-v1/providers/"
                                "alpha.source.release/manifest.json"
                }]
            }))
            with mock.patch.object(audit, "HERE", folder), (
                 mock.patch.object(audit, "INSTALLED_REGISTRY", index)):
                result = audit.installed_status()
            self.assertEqual(result["candidate_operations"],
                             ["alpha.source.release", "beta.source.release"])
            self.assertEqual(result["unregistered_candidate_operations"],
                             ["beta.source.release"])
            self.assertFalse(result["all_candidate_operations_present_in_index"])
            self.assertFalse(result["first_party_signed_enrollment_verified"])

    def test_detected_source_behavior_has_corresponding_blocker(self):
        result = audit.evaluate()
        probes = result["ephemeral_security_probes"]
        mapping = {
            "different_git_source_for_same_target": "SOURCE_TARGET_EQUIVALENCE_NOT_ENFORCED",
            "changed_count_mismatch": "DERIVED_CHANGED_COUNT_NOT_REVALIDATED",
        }
        for name, reason in mapping.items():
            self.assertEqual(probes[name] == "ACCEPTED", reason in result["blockers"])


if __name__ == "__main__":
    unittest.main()
