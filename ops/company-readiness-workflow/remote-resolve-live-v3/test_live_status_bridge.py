"""Hermetic checks for the non-authorizing live Remote Resolve bridge."""
import copy
import importlib.util
import pathlib
import unittest

HERE = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("bridge", HERE / "live_status_bridge.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
ARE = "49c562d1-8f2f-456f-b454-26816c6ba4cb"

def fixture():
    entries = [
        {"target":"all-royal-egypt:production","canonical_origin":"https://www.allroyalegypt.com",
         "site_uuid":None,"identity_registered_by_original_305":False},
        {"target":"egypt-tour-gates:production","canonical_origin":"https://egypttourgates.com",
         "site_uuid":None,"identity_registered_by_original_305":False},
        {"target":"tourism-almallahgroup:production","canonical_origin":"https://tourism.almallahgroup-mg.com",
         "site_uuid":None,"identity_registered_by_original_305":False},
        {"target":"all-royal-egypt:staging","canonical_origin":"https://staging.allroyalegypt.com",
         "site_uuid":ARE,"identity_registered_by_original_305":True},
        {"target":"tours-central:staging","canonical_origin":"https://tours.dev.mad4b.com",
         "site_uuid":"e5e64bca-ed62-48d2-b2b3-dff693cd702a","identity_registered_by_original_305":True},
    ]
    return {"policy":{"contract":"mad4b.unified-operation-policy-projection.v1",
            "targets":entries,"target_count":len(entries),"grants_created":False,"production_mutated":False},
        "identity":{},"native":{"standing_execution_enrollment":[{
            "company_code":"all-royal-egypt","environment":"staging",
            "host_proof_receipt_state":"ORIGINAL_SIGNED_HOST_PROOF_FRESH"}]},
        "crossssh":{"targets":[]},"precert":{"targets":[]}}

class BridgeTests(unittest.TestCase):
    def setUp(self):self.data=fixture()
    def test_enumerates_all_targets(self):
        self.assertEqual(m.project(self.data)["target_count"],5)
    def test_production_profile_missing_retries_independently(self):
        a=m.project(self.data)["targets"]
        self.assertTrue(all(v["retry_automatically"] for v in a[:3]))
        self.assertEqual({v["next_action"] for v in a[:3]},
                         {"DISCOVER_SITE_PROFILE_VIA_REGISTERED_CROSS_SSH"})
    def test_staging_verified_proof_is_not_authorization(self):
        x=m.project(self.data)["targets"][3]
        self.assertEqual(x["next_action"],"REQUEST_ORIGINAL_306_312_SIGNED_READ_CANARY")
        self.assertFalse(x["original_313_certified"])
    def test_controller_and_demo_are_separate(self):
        self.assertEqual(m.project(self.data)["targets"][4]["next_action"],
                         "RECONCILE_CENTRAL_DEMO_ORIGINAL_305_SEPARATELY")
    def test_exact_staging_crossssh_is_connectivity_only(self):
        self.data["crossssh"]["targets"]=[{"target":"all-royal-egypt:staging",
            "origin":"https://staging.allroyalegypt.com","site_uuid":ARE,
            "remote_wpcli_readback_verified":True}]
        x=m.project(self.data)["targets"][3]
        self.assertTrue(x["remote_wpcli_readback_observed"])
        self.assertTrue(x["remote_wpcli_is_not_grant"])
        self.assertFalse(x["grants_created"])
    def test_other_company_or_origin_cannot_claim_proof(self):
        self.data["crossssh"]["targets"]=[{"target":"all-royal-egypt:staging",
            "origin":"https://egypttourgates.com","site_uuid":ARE,
            "remote_wpcli_readback_verified":True}]
        self.assertFalse(m.project(self.data)["targets"][3]["remote_wpcli_readback_observed"])
    def test_site_uuid_drift_is_not_accepted(self):
        self.data["crossssh"]["targets"]=[{"target":"all-royal-egypt:staging",
            "origin":"https://staging.allroyalegypt.com",
            "site_uuid":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
            "remote_wpcli_readback_verified":True}]
        self.assertFalse(m.project(self.data)["targets"][3]["remote_wpcli_readback_observed"])
    def test_no_synthetic_grants_jobs_or_production_mutation(self):
        x=m.project(self.data)
        self.assertFalse(x["controller_issued_grants"])
        self.assertFalse(x["controller_dispatched_jobs"])
        self.assertFalse(x["controller_mutated_production"])
    def test_invalid_original_contract_rejected(self):
        self.data["policy"]["contract"]="untrusted"
        with self.assertRaises(m.InvalidEvidence):m.project(self.data)
    def test_invalid_origin_rejected(self):
        self.data["policy"]["targets"][0]["canonical_origin"]="http://wrong"
        with self.assertRaises(m.InvalidEvidence):m.project(self.data)
    def test_duplicate_target_rejected(self):
        self.data["policy"]["targets"].append(copy.deepcopy(self.data["policy"]["targets"][0]))
        self.data["policy"]["target_count"]+=1
        with self.assertRaises(m.InvalidEvidence):m.project(self.data)
    def test_report_cannot_claim_grant_creation(self):
        self.data["policy"]["grants_created"]=True
        with self.assertRaises(m.InvalidEvidence):m.project(self.data)

if __name__ == "__main__": unittest.main()
