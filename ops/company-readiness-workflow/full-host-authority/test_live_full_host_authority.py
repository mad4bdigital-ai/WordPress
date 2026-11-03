import copy
import unittest
from live_full_host_authority import reconcile, InvalidAuthoritySource, PROJECT_CONTRACT
from full_host_authority import HOST_CONTRACT, CAPABILITIES

class LiveAuthorityTests(unittest.TestCase):
    def setUp(self):
        self.sites = [
            dict(target="alpha:staging", site_uuid="aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
                 canonical_origin="https://staging.alpha.example",
                 identity_registered_by_original_305=True),
            dict(target="beta:production", site_uuid=None,
                 canonical_origin="https://beta.example",
                 identity_registered_by_original_305=False),
        ]
        for site in self.sites:
            site.update(
                original_305_native_ssh_active_pair_present=False,
                operations={name:dict(eligible_for_native_wordpress_operation=False)
                            for name in ("plugin.install", "content.write")},
                legacy_operations_are_not_native_write_grants=True,
                grant_or_job_issued_by_projection=False,
                original_312_job_verified_by_projection=False,
                original_313_receipt_verified_by_projection=False,
                production_mutated=False)
        self.core = {
            "contract": PROJECT_CONTRACT,
            "status": "ORIGINAL_CORE_AND_LEGACY_TRANSPORT_SEMANTICS_SEPARATED",
            "original_authority": "live_mad4b_company_execution_resolver",
            "native_ssh_grant_authority": "original_core_305_transport_grant",
            "company_count": 2, "target_count": 2, "targets": self.sites,
            "grants_created": False, "operations_executed": False, "production_mutated": False,
        }
        self.policies = {"host-a":{
            "contract": HOST_CONTRACT, "host_id": "host-a",
            "principal":"root", "revoked":False,
            "allow_general_shell":True, "allow_raw_sql":True,
            "scopes":{
                "alpha:staging": {
                    "site_uuid": self.sites[0]["site_uuid"],
                    "canonical_origin": self.sites[0]["canonical_origin"],
                    "allow_operator":True,"allow_mutation":True,"allow_sql":True,
                    "allow_production_mutation":False,
                }
            }
        }}
    def test_dynamic_census_and_missing_production_identity(self):
        result = reconcile(self.core, self.policies)
        self.assertEqual(result["target_count"], 2)
        self.assertEqual(result["company_count"], 2)
        self.assertEqual(result["targets"][0]["requested_capabilities"], list(CAPABILITIES))
        self.assertIn("EXACT_ORIGINAL_CORE_SITE_IDENTITY_PENDING",result["targets"][1]["blockers"])
        self.assertIsNone(result["targets"][1]["verified_host_scope_id"])
        self.assertFalse(result["grants_created"])
    def test_exact_existing_staging_scope(self):
        t = reconcile(self.core,self.policies)["targets"][0]
        self.assertTrue(t["full_host_scope_observed"])
        self.assertEqual(t["verified_host_scope_id"], "host-a")
        self.assertIn("ORIGINAL_305_ACTIVE_TRANSPORT_GRANT_PENDING", t["blockers"])
    def test_staging_only_grant_not_inferred_from_legacy(self):
        self.sites[0]["legacy_transport"]={"scope":"EXACT_LEGACY_TRANSPORT_CAPABILITIES",
                                           "native_execution_granted_by_legacy":False}
        self.assertFalse(reconcile(self.core,self.policies)["targets"][0]["original_305_active_pair_observed"])
    def test_wrong_site_host_scope_fails_closed(self):
        self.policies["host-a"]["scopes"]["alpha:staging"]["site_uuid"]="cccccccc-cccc-cccc-cccc-cccccccccccc"
        with self.assertRaisesRegex(InvalidAuthoritySource,"HOST_SCOPE_IDENTITY_CONFLICT"):
            reconcile(self.core,self.policies)
    def test_legacy_unbound_scope_is_pending_not_global_denial(self):
        self.policies["host-a"]["scopes"]["alpha:staging"].pop("site_uuid")
        self.policies["host-a"]["scopes"]["alpha:staging"].pop("canonical_origin")
        r=reconcile(self.core,self.policies)
        self.assertEqual(r["target_count"],2)
        self.assertIsNone(r["targets"][0]["verified_host_scope_id"])
        self.assertIn("EXACT_HOST_SCOPE_AND_BINDING_PENDING",r["targets"][0]["blockers"])
        self.assertFalse(r["grants_created"])
    def test_partial_host_scope_never_marks_full(self):
        self.policies["host-a"]["scopes"]["alpha:staging"].pop("site_uuid")
        x=reconcile(self.core,self.policies)["targets"][0]
        self.assertFalse(x["full_host_scope_observed"])
    def test_host_scope_without_full_sql_fails_ready(self):
        self.policies["host-a"]["scopes"]["alpha:staging"]["allow_sql"]=False
        self.assertFalse(reconcile(self.core,self.policies)["targets"][0]["full_host_scope_observed"])
    def test_even_full_original_pair_requires_receipt(self):
        self.sites[0]["original_305_native_ssh_active_pair_present"]=True
        for p in self.sites[0]["operations"].values():
            p["eligible_for_native_wordpress_operation"]=True
        t=reconcile(self.core,self.policies)["targets"][0]
        self.assertIn("INDEPENDENT_EXECUTION_AND_313_RECEIPT_REQUIRED", t["blockers"])
        self.assertFalse(t["operations_executed"])
    def test_duplicate_scope_fails_closed(self):
        self.sites.append(copy.deepcopy(self.sites[0]))
        self.core["target_count"]=3
        with self.assertRaisesRegex(InvalidAuthoritySource,"DUPLICATE_SITE_SCOPE"):
            reconcile(self.core,self.policies)
    def test_spoofed_projection_not_accepted(self):
        self.core["original_authority"]="legacy_ssh"
        with self.assertRaisesRegex(InvalidAuthoritySource,"LIVE_ORIGINAL_CORE_PROJECTION_REQUIRED"):
            reconcile(self.core,self.policies)
    def test_unsigned_or_revoked_host_fails_closed(self):
        self.policies["host-a"]["revoked"]=True
        with self.assertRaisesRegex(InvalidAuthoritySource,"HOST_POLICY_CONTRACT_INVALID"):
            reconcile(self.core,self.policies)
    def test_duplicate_host_claim_fails_closed(self):
        self.policies["host-b"]=copy.deepcopy(self.policies["host-a"])
        self.policies["host-b"]["host_id"]="host-b"
        with self.assertRaisesRegex(InvalidAuthoritySource,"MULTIPLE_HOSTS_CLAIM_SAME_SITE"):
            reconcile(self.core,self.policies)
    def test_production_scope_preserves_separate_approval(self):
        self.sites[1]["site_uuid"]="bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb"
        self.sites[1]["identity_registered_by_original_305"]=True
        self.policies["host-a"]["scopes"]["beta:production"]={
            "site_uuid":self.sites[1]["site_uuid"],
            "canonical_origin":self.sites[1]["canonical_origin"],
            "allow_operator":True,"allow_mutation":True,"allow_sql":True,
            "allow_production_mutation":True,
        }
        p=reconcile(self.core,self.policies)["targets"][1]
        self.assertTrue(p["full_host_scope_observed"])
        self.assertIn("SEPARATE_PRODUCTION_OPERATION_APPROVAL_REQUIRED",p["blockers"])
        self.assertFalse(p["production_mutated"])
    def test_core_cannot_report_grants_issued(self):
        self.core["grants_created"]=True
        with self.assertRaisesRegex(InvalidAuthoritySource,"LIVE_ORIGINAL_CORE_PROJECTION_REQUIRED"):
            reconcile(self.core,self.policies)
    def test_does_not_mutate_inputs(self):
        old=copy.deepcopy((self.core,self.policies))
        reconcile(self.core,self.policies)
        self.assertEqual(old,(self.core,self.policies))

if __name__ == "__main__":
    unittest.main()
