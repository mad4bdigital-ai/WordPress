"""Offline regression tests for the read-only evidence gate."""
import copy
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
from preflight import CONTRACT, assess

def example():
    company = "example-operator"
    site = "123e4567-e89b-42d3-a456-426614174000"
    url = "https://staging.example.org"
    return {
        "contract": CONTRACT, "company": company, "environment": "staging",
        "site_profile": {"site_uuid": site, "canonical_origin": url,
                         "environment": "staging", "authority_ready": True,
                         "profile_digest": "a" * 64},
        "central_signed": {"contract": "mad4b.environment.signed-v4-proof.v1",
                           "company": company, "environment": "staging",
                           "canonical_origin": url, "site_uuid": site,
                           "profile_digest": "a" * 64,
                           "centrally_verified": True, "scoped_trust_verified": True},
        "portfolio": {"contract": "mad4b.manager-company-assignment.v1",
                      "company": company, "environment": "staging",
                      "company_site_uuid": site, "canonical_origin": url,
                      "binding_verified": True, "assignment_state": "active",
                      "operations_manage": True},
        "manager": {"company": company, "environment": "staging",
                    "site_uuid": site, "member_active": True,
                    "eligible_actor_count": 1, "wordpress_actor_user_id": 26,
                    "operations_manage": True},
        "transport": {"kind": "remote_managed_wordpress",
                      "contract": "mad4b.remote-hosting-native-transport.v1",
                      "company": company, "environment": "staging",
                      "site_uuid": site, "canonical_origin": url,
                      "wordpress_actor_user_id": 26, "registered": True,
                      "native_principal_verified": True,
                      "production_mutation_allowed": False,
                      "remote_account_bound": True, "site_root_bound": True,
                      "remote_executor_identity_verified": True,
                      "wordpress_target_readback": True,
                      "signed_dispatch_supported": True},
        "native_executor": {"contract": "mad4b.signed-vps-wpcli-native.v1",
                            "company": company, "environment": "staging",
                            "site_uuid": site, "transport_kind": "remote_managed_wordpress",
                            "certified": True, "signed_job_verified": True,
                            "independent_readback_supported": True,
                            "receipt_313_verifier_certified": True}
    }

class TestPreflight(unittest.TestCase):
    def test_complete_example(self):
        report = assess(example())
        self.assertTrue(report["eligible"])
        self.assertFalse(report["write_performed"])

    def test_wrong_environment(self):
        row = example()
        row["environment"] = "production"
        self.assertIn("PRODUCTION_AND_UNKNOWN_ENVIRONMENT_DENIED", assess(row)["blockers"])

    def test_missing_company_assignment(self):
        row = example()
        row["portfolio"]["assignment_state"] = "proposed"
        self.assertIn("ENVIRONMENT_SCOPED_PORTFOLIO_ASSIGNMENT_MISSING", assess(row)["blockers"])

    def test_unsigned_site(self):
        row = example()
        row["central_signed"]["scoped_trust_verified"] = False
        self.assertIn("STAGING_SIGNED_TRUST_NOT_CURRENT", assess(row)["blockers"])

    def test_unregistered_transport(self):
        row = example()
        row["transport"]["registered"] = False
        self.assertIn("REMOTE_HOSTING_EXECUTOR_NOT_ENROLLED", assess(row)["blockers"])

    def test_unverified_receipt(self):
        row = example()
        row["native_executor"]["receipt_313_verifier_certified"] = False
        self.assertIn("NATIVE_EXECUTOR_OR_313_NOT_CERTIFIED", assess(row)["blockers"])

    def test_missing_evidence(self):
        row = example()
        del row["transport"]
        self.assertEqual(["EXACT_EVIDENCE_SCHEMA_REQUIRED"], assess(row)["blockers"])

if __name__ == "__main__":
    unittest.main()
