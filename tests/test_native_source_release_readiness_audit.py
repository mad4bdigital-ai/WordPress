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


    def test_installed_original_worker_contract_requires_exclusive_binding(self):
        result = audit.original_worker_contract_probes()
        self.assertEqual(
            result["test_scope"], "ORIGINAL_VALIDATORS_ON_EPHEMERAL_PLAN_STORE"
        )
        self.assertEqual(result["original313_accepts_plain_core_plan"], "ACCEPTED")
        self.assertIn(
            result["original313_accepts_dynamic_envelope"].split(":")[0],
            ("ACCEPTED", "DENIED"),
        )
        self.assertEqual(len(result["one_core_plan_indexed_under_operations"]), 2)
        self.assertFalse(result["job_issued"])
        self.assertFalse(result["grant_issued"])
        self.assertFalse(result["installed_worker_modified"])

    def test_original_worker_contract_holds_release_if_not_compatible(self):
        result = audit.evaluate()
        worker = result["original_worker_contract_probes"]
        if worker["original313_accepts_dynamic_envelope"] != "ACCEPTED":
            self.assertIn(
                "ORIGINAL313_PLAN_ENVELOPE_NOT_COMPATIBLE", result["blockers"]
            )
        if not worker["operation_exclusive"]:
            self.assertIn(
                "ORIGINAL306_PLAN_OPERATION_NOT_UNIQUELY_BOUND", result["blockers"]
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
            self.assertEqual(
                result["registry_hash_verified_candidate_operations"], []
            )
            self.assertEqual(
                result["registry_unverified_candidate_operations"],
                ["alpha.source.release", "beta.source.release"],
            )


    def test_source_association_does_not_false_claim_worker_enrollment(self):
        observation = audit.signed_association_admission_probe()
        self.assertTrue(
            observation["source_only_signed_association_contract_present"]
        )
        self.assertFalse(
            observation["installed_original306_enforces_exclusive_association"]
        )
        self.assertFalse(observation["original306_job_issued"])
        self.assertIn(
            "ORIGINAL306_SIGNED_ASSOCIATION_HOOK_NOT_INSTALLED",
            audit.evaluate()["blockers"],
        )

    def test_original_313_environment_evidence_must_be_compatible(self):
        found=audit.verifier_environment_contract_probe()
        self.assertTrue(found["original313_requires_env_bound_mutation_flag"])
        if not found["production_proof_compatible"]:
            self.assertIn(
                "ORIGINAL313_PRODUCTION_EVIDENCE_ENVIRONMENT_MISMATCH",
                audit.evaluate()["blockers"],
            )
        self.assertFalse(found["original_independent_signer_modified"])
        self.assertFalse(found["signed_proof_created"])

    def test_index_names_are_not_registry_digest_certification(self):
        with tempfile.TemporaryDirectory(prefix="source-registry-unauthenticated-") as temp:
            folder=Path(temp)
            generated=folder/"catalog/generated"
            generated.mkdir(parents=True)
            op="untrusted.source.release"
            (generated/(op+".manifest.json")).write_text(
                json.dumps({"operation_id":op,"provider":"untrusted"}))
            root=folder/"registry"
            root.mkdir()
            (root/"index.json").write_text(json.dumps({"providers":[{
              "manifest":"dynamic-operation-registry-v1/providers/"+
                         op+"/manifest.json",
              "sha256":"0"*64
            }]}))
            with mock.patch.object(audit, "HERE",folder), (
                 mock.patch.object(audit, "INSTALLED_REGISTRY",root/"index.json")):
                result=audit.installed_status()
            self.assertTrue(result["all_candidate_operations_present_in_index"])
            self.assertFalse(result["all_candidate_manifests_sha_verified"])
            self.assertEqual(result["registry_unverified_candidate_operations"],[op])

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
