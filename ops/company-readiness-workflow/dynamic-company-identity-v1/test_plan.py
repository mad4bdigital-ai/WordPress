#!/usr/bin/env python3
"""Offline acceptance for dynamic company identity planning."""
import importlib.util
import unittest
from pathlib import Path

spec=importlib.util.spec_from_file_location("identity_plan",Path(__file__).with_name("plan.py"))
p=importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)
UUID="d745d81f-6fc4-5c6a-99dd-d953c92137bf"
def record(origin,uuid=UUID,ready=True):
    return {"canonical_origin":origin,"site_uuid":uuid,"identity_candidate":ready}
class Tests(unittest.TestCase):
    def test_verified_staging(self):
        a=p.classify("egypt-tour-gates:staging",record("https://staging.egypttourgates.com"),
                     {"signed_cryptographic_proof_current":True})
        self.assertTrue(a["identity_verified"])
        self.assertFalse(a["grant_305_issued_by_plan"])
    def test_staging_missing_plugin(self):
        a=p.classify("egypt-tour-gates:staging",record("https://staging.egypttourgates.com"),
                     {"endpoint_http":404})
        self.assertEqual(a["next_action"],"PROVISION_SIGNED_V4_SITE_PROVIDER")
    def test_staging_missing_trust(self):
        a=p.classify("egypt-tour-gates:staging",record("https://staging.egypttourgates.com"),
                     {"endpoint_http":403,"trust_configured":False})
        self.assertEqual(a["next_action"],"REGISTER_INDEPENDENTLY_VERIFIED_SITE_PUBLIC_KEY")
    def test_production_not_self_issued(self):
        a=p.classify("egypt-tour-gates:production",record("https://egypttourgates.com"),
                     {"signed_cryptographic_proof_current":True})
        self.assertEqual(a["next_action"],"PRODUCTION_ORIGINAL_SIGNED_ISSUER_REQUIRED")
        self.assertFalse(a["identity_verified"])
        self.assertFalse(a["production_mutated"])
    def test_production_without_uuid(self):
        a=p.classify("egypt-tour-gates:production",record("https://egypttourgates.com",uuid=None))
        self.assertEqual(a["next_action"],"ENROLL_ORIGINAL_EXACT_SITE_PROFILE")
    def test_central_not_a_managed_target(self):
        a=p.classify("tours-central:staging",record("https://tours.dev.mad4b.com"))
        self.assertEqual(a["next_action"],"CENTRAL_CONTROLLER_NOT_A_MANAGED_SITE")
    def test_origin_injection_rejected(self):
        for origin in ["http://staging.egypttourgates.com",
                       "https://staging.egypttourgates.com/evil",
                       "https://u:p@staging.egypttourgates.com",
                       "https://staging.egypttourgates.com:8443"]:
            with self.subTest(origin=origin):
                with self.assertRaises(ValueError):
                    p.classify("egypt-tour-gates:staging",record(origin))
    def test_bad_uuid_rejected(self):
        with self.assertRaises(ValueError):
            p.classify("egypt-tour-gates:staging",record("https://staging.egypttourgates.com",uuid="bad"))
    def test_invalid_tenant_rejected(self):
        with self.assertRaises(ValueError):
            p.classify("a/b:staging",record("https://staging.egypttourgates.com"))
    def test_source_count_mismatch_refused(self):
        def fetch(argv):
            return {"contract":p.CONTRACT,"central_origin":"https://tours.dev.mad4b.com",
                    "mutation_performed":False,"production_mutation":False,
                    "targets":2,"companies":1,"registrations":{"egypt-tour-gates:production":record("https://egypttourgates.com")}}
        with self.assertRaisesRegex(RuntimeError,"ORIGINAL_REGISTRY_NOT_TRUSTED"):
            p.plan(fetch)
    def test_multi_company_read_only_without_grants(self):
        sources={"all-royal-egypt:production":record("https://www.allroyalegypt.com",None),
                 "egypt-tour-gates:staging":record("https://staging.egypttourgates.com")}
        def fetch(argv):
            if argv[-1]=="status":
                return {"contract":p.CONTRACT,"central_origin":"https://tours.dev.mad4b.com",
                        "mutation_performed":False,"production_mutation":False,
                        "targets":2,"companies":2,"registrations":sources}
            return {"contract":"mad4b.company.signed-v4-preflight.v1","company_id":"egypt-tour-gates",
                    "environment":"staging","canonical_origin":"https://staging.egypttourgates.com",
                    "site_uuid":UUID,"remote_mutation_performed":False,
                    "production_mutation":False,"signed_cryptographic_proof_current":True}
        result=p.plan(fetch)
        self.assertEqual(result["target_count"],2)
        self.assertEqual(result["company_count"],2)
        self.assertFalse(result["grants_created"])
        self.assertFalse(result["manager_approvals_created"])
        self.assertFalse(result["production_mutated"])
    def test_readonly_transient_retry_exactly_once(self):
        from unittest import mock
        calls=[]
        def once(*args,**kwargs):
            calls.append(1)
            if len(calls)==1: raise RuntimeError("WPCLI_DYNAMIC_ACTOR_READBACK_DENIED")
            return {"verified":True}
        with mock.patch.object(p,"wp_once",side_effect=once):
            self.assertTrue(p.wp(["mad4b-company-env","status"])["verified"])
        self.assertEqual(len(calls),2)
    def test_identity_or_permission_refusal_not_retried(self):
        from unittest import mock
        with mock.patch.object(p,"wp_once",side_effect=RuntimeError("WPCLI_DYNAMIC_ACTOR_UNVERIFIED")) as call:
            with self.assertRaisesRegex(RuntimeError,"UNVERIFIED"):
                p.wp(["mad4b-company-env","status"])
        self.assertEqual(call.call_count,1)
if __name__=="__main__":
    unittest.main()
