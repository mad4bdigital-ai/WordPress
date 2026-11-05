#!/usr/bin/env python3
"""Hermetic source contract guard, not a live deployment or 305/313 attestation."""
import ast
import importlib.util
from unittest import mock
import pathlib
import subprocess
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parent
CORE = ROOT / "company-unified-operation-policy.py"
RESOLVER = ROOT / "universal-runtime" / "resolver.py"

class UnifiedPolicySourceTests(unittest.TestCase):
    def test_projection_selftest(self):
        completed = subprocess.run(
            [sys.executable, str(CORE), "selftest"], capture_output=True, text=True,
            timeout=15, check=False)
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertIn('"tests": 10', completed.stdout)

    def test_source_functions_present(self):
        source = RESOLVER.read_text()
        functions = {n.name for n in ast.parse(source).body if isinstance(n, ast.FunctionDef)}
        self.assertTrue({"inspect_registered_release_readback", "inspect_authority",
                         "inventory", "show_status"}.issubset(functions))
        self.assertNotIn('target=="egypt-tour-gates:staging"', source)

    def test_transport_is_not_authorization(self):
        source = RESOLVER.read_text()
        self.assertIn('"native_write_granted_by_observer":False', source)
        self.assertIn('"original_core_operation_authority_separate":True', source)
        projection = CORE.read_text()
        self.assertIn("'native_execution_granted_by_legacy':False", projection)
        self.assertIn("'original_authority':'live_mad4b_company_execution_resolver'", projection)

    def test_no_grant_creating_source_contract(self):
        projection = CORE.read_text()
        self.assertIn("'grants_created':False", projection)
        self.assertIn("'operations_executed':False", projection)
        self.assertIn("'production_mutated':False", projection)

    def test_full_host_capability_catalog_is_non_authorizing(self):
        spec = importlib.util.spec_from_file_location("mad4b_unified_policy", CORE)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        catalog = module.full_host_catalog()
        self.assertEqual(len(catalog["capabilities"]), 13)
        self.assertEqual(catalog["applies_to"], ["staging", "production"])
        self.assertFalse(catalog["capability_grants_created_by_catalog"])
        self.assertFalse(catalog["execution_enabled_by_catalog"])
        self.assertIn("host.shell", [x["id"] for x in catalog["capabilities"]])
        self.assertIn("wordpress.wpcli", [x["id"] for x in catalog["capabilities"]])
        self.assertIn("database.sql.write", [x["id"] for x in catalog["capabilities"]])
        self.assertIn("native.rollback", [x["id"] for x in catalog["capabilities"]])

    def test_full_host_preview_is_exact_site_per_environment(self):
        spec = importlib.util.spec_from_file_location("mad4b_unified_policy", CORE)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        catalog = module.full_host_catalog()
        original = {"site_identity_registered": True,
                    "original_matched_active_transport_grant_pairs": 999}
        identities = [
            {"company": "company-one", "environment": "staging",
             "site_uuid": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
             "canonical_origin": "https://one.example.test"},
            {"company": "company-two", "environment": "production",
             "site_uuid": "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb",
             "canonical_origin": "https://two.example.test"},
        ]
        plans = [module.full_host_provisioning_preview(site, original, catalog) for site in identities]
        self.assertEqual(plans[0]["capabilities"], plans[1]["capabilities"])
        self.assertNotEqual(plans[0]["site_uuid"], plans[1]["site_uuid"])
        self.assertEqual(plans[1]["environment"], "production")
        self.assertTrue(all(x["grant_reconciliation_requires_original_core"] for x in plans))
        self.assertTrue(all(x["cross_company_wildcard_allowed"] is False for x in plans))
        self.assertTrue(all(x["grant_issued_by_preview"] is False for x in plans))
        self.assertTrue(all(x["execution_authorized_by_preview"] is False for x in plans))
        self.assertTrue(all(x["production_mutated"] is False for x in plans))

    def test_full_host_preview_denies_implicit_enrollment(self):
        spec = importlib.util.spec_from_file_location("mad4b_unified_policy", CORE)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        catalog = module.full_host_catalog()
        site = {"company": "company-one", "environment": "production",
                "site_uuid": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
                "canonical_origin": "https://one.example.test"}
        x = module.full_host_provisioning_preview(site, {"site_identity_registered": False}, catalog)
        self.assertEqual(x["scope_state"], "PENDING_EXACT_SITE_ENROLLMENT")
        self.assertFalse(x["grant_issued_by_preview"])

    def test_catalog_rejects_authority_self_assertion(self):
        spec = importlib.util.spec_from_file_location("mad4b_unified_policy", CORE)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        catalog = module.full_host_catalog()
        unsafe = {**catalog, "capability_grants_created_by_catalog": True}
        with mock.patch.object(module, "load_json", return_value=unsafe):
            with self.assertRaisesRegex(RuntimeError, "AUTHORITY_DRIFT"):
                module.full_host_catalog()
        unsafe = {**catalog, "binding_required": ["site_uuid"]}
        with mock.patch.object(module, "load_json", return_value=unsafe):
            with self.assertRaisesRegex(RuntimeError, "BINDING_INCOMPLETE"):
                module.full_host_catalog()

    def test_full_host_original_305_transitions_are_not_fake_grants(self):
        spec = importlib.util.spec_from_file_location("mad4b_unified_policy", CORE)
        m = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(m)
        catalog = m.full_host_catalog()
        site = {"company": "new-company", "environment": "production",
                "site_uuid": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
                "canonical_origin": "https://new-company.example.test"}
        base = {"site_identity_registered": True,
                "original_persistent_full_wpcli_grants": 0,
                "original_matched_active_transport_grant_pairs": 0,
                "original_matched_pending_transport_grant_pairs": 0}
        x = m.full_host_provisioning_preview(site, base, catalog)
        self.assertEqual(x["scope_state"], "PENDING_ORIGINAL_305_OWNER_VERIFIED_STANDING_GRANT")
        self.assertFalse(x["grant_issued_by_preview"])
        pending = {**base, "original_persistent_full_wpcli_grants": 1,
                   "original_matched_pending_transport_grant_pairs": 1}
        x = m.full_host_provisioning_preview(site, pending, catalog)
        self.assertEqual(x["scope_state"], "PENDING_EXACT_HOST_PROOF_AND_NATIVE_TRANSPORT")
        self.assertFalse(x["host_execution_proof_verified_by_preview"])
        active = {**pending, "original_matched_active_transport_grant_pairs": 1}
        x = m.full_host_provisioning_preview(site, active, catalog)
        self.assertEqual(x["scope_state"], "PENDING_INDEPENDENT_NATIVE_EXECUTOR_AND_313_READBACK")
        self.assertFalse(x["execution_authorized_by_preview"])
        self.assertFalse(x["production_mutated"])
        with self.assertRaisesRegex(RuntimeError, "GRANT_COUNTER_INVALID"):
            m.full_host_provisioning_preview(site,
                {**active, "original_matched_active_transport_grant_pairs": True}, catalog)

    def test_company_enrollment_event_rechecks_original_core(self):
        spec = importlib.util.spec_from_file_location("mad4b_unified_policy", CORE)
        m = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(m)
        site = {"company": "new-company", "environment": "production",
                "site_uuid": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
                "canonical_origin": "https://new-company.example.test"}
        original_operations = {op: {
            "state": "BLOCKED_MISSING_AUTHORITY",
            "execution_allowed": False,
            "remote_write_permitted": False,
            "blockers": ["missing_exact_grant"]
        } for op in m.OPERATIONS}
        plans = {"target_count": 1, "targets": [{**site,
                    "original_operations": original_operations}]}
        grants = {"target_count": 1, "targets": [
            {"company": "new-company", "environment": "production",
             "site_identity_registered": True,
             "original_persistent_full_wpcli_grants": 0,
             "original_matched_active_transport_grant_pairs": 0,
             "original_matched_pending_transport_grant_pairs": 0}]}
        legacy = {"contract": "mad4b.universal-runtime-reconciler.v1",
                  "authority_model": "identity_proof_plus_capability_specific_provider_authority",
                  "scopes": {}}
        answer = m.project_enrollment_event(plans, grants, legacy, site)
        self.assertTrue(answer["original_core_registration_rechecked"])
        self.assertEqual(answer["full_host_authority"]["capability_count"], 13)
        self.assertEqual(answer["full_host_authority"]["environment"], "production")
        self.assertFalse(answer["grant_issued"])
        self.assertFalse(answer["native_operation_executed"])
        with self.assertRaisesRegex(RuntimeError, "SCOPE_CHANGED"):
            m.project_enrollment_event(plans, grants, legacy,
                {**site, "site_uuid": "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb"})
        with self.assertRaisesRegex(RuntimeError, "NOT_IN_ORIGINAL_REGISTRY"):
            m.project_enrollment_event(plans, grants, legacy,
                {**site, "company": "other-company"})
        with self.assertRaisesRegex(RuntimeError, "EVENT_INVALID"):
            m.project_enrollment_event(plans, grants, legacy,
                {**site, "grant": True})

if __name__ == "__main__":
    unittest.main()
