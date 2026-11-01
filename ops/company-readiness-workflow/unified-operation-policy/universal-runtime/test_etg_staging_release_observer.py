import importlib.util
from pathlib import Path
import unittest

path=Path(__file__).with_name('etg_staging_release_observer.py')
spec=importlib.util.spec_from_file_location('etg_staging_release_observer',path)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

SCOPE={"site_uuid":m.UUID,"environment":"staging","canonical_origin":m.SITE,
       "release_observer":m.CONTRACT,"operations":[]}
IDENTITY={"site_profile":{"site_uuid":m.UUID,"environment":"staging",
           "canonical_origin":m.SITE,"deployment_binding_match":True,"origin_match":True}}

class TestEtgReleaseObserver(unittest.TestCase):
    def test_candidate_matches_exact_archive(self):
        self.assertEqual(m.candidate_check()["site_uuid"],m.UUID)
    def test_unknown_scope_cannot_invoke_read(self):
        def disallowed(_):raise AssertionError('must not run SSH')
        result=m.inspect({},read=disallowed)
        self.assertEqual(result["identity"]["state"],"review_required")
    def test_no_write_operation_in_scope(self):
        scope={**SCOPE,"operations":["remote_file_transfer:verified_zip"]}
        result=m.inspect(scope,read=lambda _:None)
        self.assertEqual(result["identity"]["state"],"review_required")
    def test_runtime_drift_is_explicit(self):
        calls={"status":IDENTITY,
               "package verify":{"verified":False,"runtime":{"manifest_valid":True,
                                 "runtime_manifest_match":False,"stale":True,
                                 "source_commit_sha":"0e83ec7ecfdb02ff2f946753721921e4e45523c7"}}}
        r=m.inspect(SCOPE,read=calls.get,edge=lambda:(503,b""))
        self.assertEqual(r["authentication_routes"]["reason"],"mcp_runtime_bootstrap_http_503")
        self.assertEqual(r["transport"]["state"],"verified")
        self.assertEqual(r["identity"]["state"],"verified")
        self.assertEqual(r["package"]["state"],"blocked_dependency")
        self.assertEqual(r["operation_admission"]["state"],"blocked_authority")
    def test_matching_package_does_not_grant_apply(self):
        plan=m.candidate_check()
        calls={"status":IDENTITY,"package verify":{"verified":True,
            "runtime":{"runtime_manifest_match":True,"stale":False,
                       "source_commit_sha":plan["source_commit_sha"],
                       "package_manifest_digest":plan["manifest_digest"]}}}
        r=m.inspect(SCOPE,read=calls.get,edge=lambda:(401,b""))
        self.assertEqual(r["authentication_routes"]["state"],"awaiting_provider")
        self.assertEqual(r["package"]["state"],"verified")
        self.assertEqual(r["operation_admission"]["state"],"blocked_authority")
        self.assertFalse(r["package"]["evidence"]["release_apply_authorized"])
    def test_transport_outage_does_not_forge_receipt(self):
        def bad(_):raise RuntimeError('offline')
        r=m.inspect(SCOPE,read=bad)
        self.assertEqual(r["transport"]["state"],"retry")
        self.assertNotEqual(r["package"]["state"],"verified")

if __name__=="__main__":unittest.main()
