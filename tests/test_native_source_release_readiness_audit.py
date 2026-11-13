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
        if worker["original313_accepts_plain_core_plan"] != "ACCEPTED":
            self.assertIn(
                "ORIGINAL313_CORE_PLAN_NOT_COMPATIBLE", result["blockers"]
            )
        else:
            self.assertNotIn("ORIGINAL313_CORE_PLAN_NOT_COMPATIBLE",result["blockers"])
        # An envelope is intentionally rejected and cannot block a correct
        # plain-plan Original306/313 release.
        self.assertNotIn("ORIGINAL313_PLAN_ENVELOPE_NOT_COMPATIBLE",result["blockers"])
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


    def test_worker_source_and_installed_hash_drift_is_reported(self):
        state=audit.signed_association_admission_probe()
        tracked=state["tracked_original306_worker_sha256"]
        installed=state["installed_original306_worker_sha256"]
        self.assertEqual(len(tracked),64)
        self.assertEqual(len(installed),64)
        self.assertEqual(
            state["tracked_and_installed_original306_worker_match"],
            tracked==installed
        )
        if tracked!=installed:
            self.assertIn(
                "ORIGINAL306_SOURCE_INSTALLED_WORKER_DRIFT",
                audit.evaluate()["blockers"]
            )

    def test_source_association_does_not_false_claim_worker_enrollment(self):
        observation = audit.signed_association_admission_probe()
        self.assertTrue(
            observation["source_only_signed_association_contract_present"]
        )
        self.assertTrue(
            observation["source_only_associated_worker_index_present"]
        )
        self.assertTrue(
            observation["source_only_associated_worker_index_uses_signed_resolver"]
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
        self.assertTrue(found["receipt_scope_and_environment_contract_fixture_verified"])
        if not found["production_proof_compatible"]:
            self.assertIn(
                "ORIGINAL313_PRODUCTION_EVIDENCE_ENVIRONMENT_MISMATCH",
                audit.evaluate()["blockers"],
            )
        self.assertFalse(found["original_independent_signer_modified"])
        self.assertFalse(found["signed_proof_created"])

    def test_original_signed_grants_are_distinct_and_never_fresh_authority(self):
        grants=audit.original_historical_grants_probe()
        self.assertFalse(grants["current_original305_job_verified"])
        self.assertFalse(grants["fresh_original312_verified"])
        self.assertFalse(grants["grants_extended_or_issued"])
        self.assertFalse(grants["plans_or_wordpress_mutated"])
        self.assertEqual(grants["state"],
            "HISTORICAL_SIGNED_GRANTS_ONLY__CURRENT_AUTHORITY_UNVERIFIED")
        self.assertGreaterEqual(grants["matched_grant_file_count"],
                                grants["distinct_plans_with_valid_grant_evidence"])
        self.assertIn(
            "LEGACY_HISTORICAL_SIGNED_GRANT_COVERAGE_INCOMPLETE",
            audit.evaluate()["blockers"],
        )

    def test_job_claim_lifecycle_preview_never_authorizes_migration(self):
        state=audit.original_job_lifecycle_contract_probe()
        self.assertTrue(state["candidate_lifecycle_classifier_available"])
        self.assertTrue(state["source_only_redacted_lifecycle_classifier_present"])
        self.assertTrue(state["source_only_wp_job_claim_readback_php_present"])
        self.assertFalse(state["source_only_readback_not_bootstrapped"])
        self.assertTrue(state["source_candidate_readback_bootstrapped"])
        self.assertFalse(state["first_party_current_job_claim_readback_available"])
        self.assertFalse(state["release_authorized"])

    def test_historical_finish_is_not_fresh_signed_original_312(self):
        historical=audit.original_finish_evidence_probe()
        self.assertFalse(historical["original305_current_state_verified"])
        self.assertFalse(historical["original312_current_eligibility_verified"])
        self.assertFalse(historical["live_site_mutated"])
        self.assertIn(
            "ORIGINAL305312_CURRENT_JOB_CLAIM_NOT_VERIFIED",
            audit.evaluate()["blockers"],
        )

    def test_legacy_plan_inventory_blocks_unsafe_shared_contract_rollout(self):
        report=audit.legacy_plan_compatibility_probe()
        self.assertEqual(report["plan_store_modified"],False)
        self.assertEqual(report["provider_enrolled"],False)
        final=audit.evaluate()
        self.assertEqual(final["state"],"HOLD")
        if report["state"]=="HOLD__LEGACY_PLAN_AUDIT_UNVERIFIED":
            self.assertIn("LEGACY_PLAN_STORE_IMPACT_UNVERIFIED",final["blockers"])
        elif report["ambiguous_existing_plan_count"]>0:
            self.assertIn(
                "LEGACY_EXISTING_PLANS_SHARE_NEW_SOURCE_CONTRACT",
                final["blockers"],
            )

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
