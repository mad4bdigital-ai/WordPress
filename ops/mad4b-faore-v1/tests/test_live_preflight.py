import sys
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from live_preflight import check_company, PROFILE_OPTION, REGISTRY_OPTION

PROFILE={
 "contract":"mad4b.site-profile.v2",
 "environment":"staging",
 "canonical_origin":"https://tours.dev.mad4b.com",
 "site_uuid":"e5e64bca-ed62-48d2-b2b3-dff693cd702a",
}
ROW={
 "company_code":"wayfatours",
 "host_binding_verified":True,
 "environment":"production",
 "company_site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
 "canonical_origin":"https://wayfatours.com",
}


class LiveProviderPreflightTests(unittest.TestCase):
    def dataset(self, profile=PROFILE, registry=None):
        data={
            PROFILE_OPTION:profile,
            REGISTRY_OPTION:{"wayfatours":ROW} if registry is None else registry,
        }
        return data.__getitem__

    def test_missing_native_target_blocks_original_313(self):
        result=check_company("wayfatours",self.dataset(),
            native=lambda *args:{"state":"BLOCKED","reason":"TARGET_CONTAINER_UNAVAILABLE"},
            broker=lambda _:{"event_count":0,"evidence_state":"Unverified"})
        self.assertEqual(result["native_target_reason"],"TARGET_CONTAINER_UNAVAILABLE")
        self.assertEqual(result["state"],"BLOCKED")
        self.assertFalse(result["stage_313_verified"])
        self.assertFalse(result["new_grants_issued"])
        self.assertFalse(result["mutation_performed"])

    def test_container_running_is_not_execution_authority(self):
        result=check_company("wayfatours",self.dataset(),
            native=lambda *args:{"state":"TARGET_OBSERVED","reason":"FURTHER_NATIVE_ADMISSION_REQUIRED",
                                 "database_state":"DATABASE_OBSERVED","discovered_dynamically":True,
                                 "coolify_service_uuid":"a"*24},
            broker=lambda _:{"event_count":3,"evidence_state":"Observed"})
        self.assertEqual(result["state"],"BLOCKED")
        self.assertIn("NATIVE_313_INDEPENDENT_READBACK_REQUIRED",result["checks"])
        self.assertFalse(result["stage_313_verified"])
        self.assertEqual(result["native_database_state"],"DATABASE_OBSERVED")
        self.assertEqual(result["native_runtime_discovery"],"dynamic_coolify_site_profile")

    def test_database_missing_fails_closed(self):
        result=check_company("wayfatours",self.dataset(),
            native=lambda *args:{"state":"TARGET_OBSERVED","reason":"FURTHER_NATIVE_ADMISSION_REQUIRED",
                                 "database_state":"BLOCKED"},
            broker=lambda _: {"event_count":0,"evidence_state":"Unverified"})
        self.assertIn("NATIVE_DATABASE_TARGET_UNVERIFIED",result["checks"])
        self.assertEqual(result["state"],"BLOCKED")

    def test_wrong_central_and_company_scope_fail_before_docker(self):
        calls=[]
        profile=dict(PROFILE,environment="production")
        report=check_company("wayfatours",self.dataset(profile),
            native=lambda *args:calls.append("docker"),broker=lambda _: {})
        self.assertIn("CENTRAL_SITE_PROFILE_MISMATCH",report["checks"])
        self.assertFalse(calls)
        report=check_company("other",self.dataset(),
            native=lambda *args:calls.append("docker"),broker=lambda _: {})
        self.assertIn("COMPANY_REGISTRY_BINDING_UNVERIFIED",report["checks"])
        self.assertFalse(calls)


if __name__=="__main__":
    unittest.main()
