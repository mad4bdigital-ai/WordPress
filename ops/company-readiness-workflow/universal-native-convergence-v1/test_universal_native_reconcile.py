#!/usr/bin/env python3
"""Exact-site native transport/proof state contracts, without host or WP writes."""
import ast
import importlib.util
import pathlib
import unittest

HERE=pathlib.Path(__file__).resolve().parent
SOURCE=HERE/"company-native-contract-auto-reconcile.py"
spec=importlib.util.spec_from_file_location("universal_native_reconcile",SOURCE)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

def plan(company="sample-company",environment="staging",identity=True,grant=1,pending=1,active=0):
    return module.standing_default_actions(
      {"targets":[{"company_code":company,"environment":environment,
                   "state":"BLOCKED_SOURCE_RUNTIME_INTEGRATION_GAPS"}]},
      {"targets":[{"company":company,"environment":environment,
                   "site_identity_registered":identity,
                   "original_persistent_full_wpcli_grants":grant,
                   "original_pending_full_wpcli_transports":pending,
                   "original_expired_one_time_grants":0,
                   "original_matched_active_transport_grant_pairs":active,
                   "original_matched_pending_transport_grant_pairs":pending}]})[0]

class UniversalNativeConvergenceTests(unittest.TestCase):
    def test_staging_pending_is_not_active(self):
        x=plan()
        self.assertFalse(x["standing_execution_eligible"])
        self.assertFalse(x["original_active_transport_present"])
        self.assertEqual(x["next_original_typed_transition"],
          "VERIFY_ORIGINAL_HOST_PROOF_AND_ACTIVATE_ENROLLED_NATIVE_TRANSPORT")

    def test_production_pending_is_not_active(self):
        x=plan(environment="production")
        self.assertFalse(x["standing_execution_eligible"])
        self.assertEqual(x["original_pending_transport_count"],1)

    def test_missing_production_identity_is_not_enrolled(self):
        x=plan(environment="production",identity=False,grant=0,pending=0)
        self.assertEqual(x["next_original_typed_transition"],
          "RECONCILE_ORIGINAL_COMPANY_SITE_IDENTITY")
        self.assertFalse(x["original_persistent_grant_present"])

    def test_missing_grant_denied_both_environments(self):
        for env in ("staging","production"):
            with self.subTest(env=env):
                x=plan(environment=env,grant=0,pending=0)
                self.assertFalse(x["standing_execution_eligible"])
                self.assertEqual(x["next_original_typed_transition"],
                  "ENROLL_OWNER_VERIFIED_ORIGINAL_305_PERSISTENT_WPCLI_FULL")

    def test_active_transport_does_not_implicitly_authorize_execution(self):
        x=plan(active=1,pending=0)
        self.assertTrue(x["original_active_transport_present"])
        self.assertFalse(x["standing_execution_eligible"])

    def test_duplicate_site_key_fails_closed(self):
        one={"company":"sample-company","environment":"staging",
             "site_identity_registered":True,"original_persistent_full_wpcli_grants":1,
             "original_pending_full_wpcli_transports":1,
             "original_expired_one_time_grants":0,
             "original_matched_active_transport_grant_pairs":0,
             "original_matched_pending_transport_grant_pairs":1}
        with self.assertRaisesRegex(RuntimeError,"DUPLICATE_CHANNEL_TARGET"):
            module.standing_default_actions({"targets":[]},{"targets":[one,one]})

    def test_no_hardcoded_tenant_or_signer_apply_in_reconcile(self):
        source=SOURCE.read_text()
        ast.parse(source)
        self.assertNotIn("wayfa_host_proof_plan",source)
        self.assertNotIn("refresh_signed_host_proof",source)
        self.assertIn("READY_FOR_ORIGINAL_306_312_SIGNED_READ_CANARY",source)
        self.assertIn("original_transport_activation_performed",source)
        self.assertIn("original_305_pending_transport_is_expected",source)
        self.assertIn("0<=now-proof['proof_epoch']<=1800",source)
        self.assertNotIn("ACTIVATE_ORIGINAL_ENROLLED_305_TRANSPORT_VIA_AUTHENTICATED_ISSUER",source)

    def test_exact_proof_requires_current_original_contract(self):
        source=SOURCE.read_text()
        for token in ("mad4b.company-native-host-proof.v1",
                      "HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING",
                      "proof.get('company')==row['company_code']",
                      "proof.get('environment')==row['environment']",
                      "proof.get('native_executor_certified') is False",
                      "proof.get('production_mutation') is False"):
            with self.subTest(token=token):
                self.assertIn(token,source)

if __name__=="__main__":
    unittest.main()
