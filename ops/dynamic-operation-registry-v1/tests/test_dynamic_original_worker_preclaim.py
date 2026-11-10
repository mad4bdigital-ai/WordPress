"""Original 306 dynamic claim must not reserve a Job without an exact signed Grant."""
from __future__ import annotations
import importlib.util
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/"integrations/patch_current_worker_preclaim.py"
sys.path.insert(0,str(ROOT/"integrations"))
sys.path.insert(0,str(ROOT))
import patch_current_worker_preclaim as patcher
import worker_adapter

ORIGINAL=Path("/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py")

class PreclaimAdmissionTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.base=Path(self.tmp.name)
        self.worker=self.base/"worker.py"
        source=ORIGINAL.read_text()
        # Test both before the first-party deployment and after it: the
        # one-time patcher must reject a second application, while signed
        # grant admission remains fully testable on an installed Worker.
        if "def dynamic_release_grant_ready(job,command_sha):" in source:
            with self.assertRaisesRegex(RuntimeError,"ALREADY_INSTALLED"):
                patcher.transform(source)
            self.worker.write_text(source)
        else:
            self.worker.write_text(patcher.transform(source))
        spec=importlib.util.spec_from_file_location("fixture_original306_preclaim",self.worker)
        self.module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.module.ROOT=self.base
        self.module.DYNAMIC_RELEASE_GRANTS=self.base/"grants"
        self.module.DYNAMIC_RELEASE_PLANS=self.base/"plans"
        self.module.DYNAMIC_RELEASE_GRANTS.mkdir()
        self.module.DYNAMIC_RELEASE_PLANS.mkdir()
        self.job="a"*32
        self.sha="b"*64
        self.plan_sha="c"*64
        self.plan={"plan_sha256":self.plan_sha,"company":"wayfatours",
                   "environment":"production"}
        self.grant={"original_job_id":self.job}
        self.index=types.SimpleNamespace(
            root=self.base/"registry",
            resolve=lambda _:{"version":3,"dependencies":[{}, {}, {}]})
        self.module.dynamic_provider_registry=lambda:(self.index,None,None)
        self.release=types.ModuleType("company_code_release")
        self.release.__file__=str(self.index.root/"company-code-release-v1"/"company_code_release.py")
        self.release.POLICY_PATH=self.base/"policy.json"
        self.release.trusted_config=mock.Mock(return_value={"policy":True})
        self.release.confirm_plan=mock.Mock()
        self.release.assert_runtime_binding=mock.Mock()
        self.release.verify_grant=mock.Mock()
        self.handler=types.ModuleType("original_313_host_handler")
        self.handler.__file__=str(self.index.root/"company-code-release-v1"/"original_313_host_handler.py")
        self.handler.trusted_load=mock.Mock(side_effect=lambda path:
            self.grant if path.name==self.job+".json" else self.plan)
        self.stub=mock.patch.dict(sys.modules,{
            "company_code_release":self.release,
            "original_313_host_handler":self.handler})
        self.stub.start()
        self.indexed=mock.patch.object(worker_adapter,"indexed_plans",
                 return_value={self.sha:{"operation":"company.code.release",
                                        "plan":self.plan}})
        self.indexed.start()

    def tearDown(self):
        self.stub.stop()
        self.indexed.stop()
        self.tmp.cleanup()

    def test_generated_original_worker_contains_real_root_store_constants(self):
        fresh=importlib.util.module_from_spec(importlib.util.spec_from_file_location(
            "firstparty_unmodified_grant_preclaim",
            self.worker))
        importlib.util.spec_from_file_location(
            "firstparty_unmodified_grant_preclaim",
            self.worker).loader.exec_module(fresh)
        self.assertEqual(fresh.DYNAMIC_RELEASE_GRANTS,
            Path("/var/lib/mad4b/company-code-release/grants"))
        self.assertEqual(fresh.DYNAMIC_RELEASE_PLANS,
            Path("/var/lib/mad4b/company-code-release/plans"))
        self.assertFalse(fresh.dynamic_release_grant_ready("a"*32,"0"*64))

    def test_missing_original_signed_release_grant_denies_before_claim(self):
        self.assertFalse(self.module.dynamic_release_grant_ready(self.job,self.sha))
        self.release.verify_grant.assert_not_called()

    def test_claim_uses_exact_stored_plan_original_job_and_signed_broker_grant(self):
        (self.base/"grants"/(self.job+".json")).write_text("{}")
        self.assertTrue(self.module.dynamic_release_grant_ready(self.job,self.sha))
        self.release.confirm_plan.assert_called_once_with(
            self.plan,{"policy":True},expect_preimage=True)
        self.release.assert_runtime_binding.assert_called_once()
        self.release.verify_grant.assert_called_once_with(
            self.plan,{"policy":True},self.grant,"apply")

    def test_wrong_original_job_denied_even_with_grant_file(self):
        (self.base/"grants"/(self.job+".json")).write_text("{}")
        self.grant["original_job_id"]="d"*32
        self.assertFalse(self.module.dynamic_release_grant_ready(self.job,self.sha))
        self.release.verify_grant.assert_not_called()

    def test_invalid_registered_operation_denies_before_grant_verification(self):
        (self.base/"grants"/(self.job+".json")).write_text("{}")
        with mock.patch.object(worker_adapter,"indexed_plans",
                return_value={self.sha:{"operation":"not-release",
                                        "plan":self.plan}}):
            self.assertFalse(self.module.dynamic_release_grant_ready(self.job,self.sha))
        self.release.verify_grant.assert_not_called()

    def test_mismatched_imported_module_source_rejected(self):
        (self.base/"grants"/(self.job+".json")).write_text("{}")
        self.handler.__file__="/tmp/untrusted-worktree/original_313_host_handler.py"
        self.assertFalse(self.module.dynamic_release_grant_ready(self.job,self.sha))
        self.release.verify_grant.assert_not_called()

    def test_existing_reservation_never_can_preclaim_again(self):
        (self.base/"grants"/(self.job+".json")).write_text("{}")
        (self.base/"reserved").mkdir()
        (self.base/"reserved"/(self.job+".reserved")).write_text("reserved")
        self.assertFalse(self.module.dynamic_release_grant_ready(self.job,self.sha))
        self.release.verify_grant.assert_not_called()

if __name__=="__main__":
    unittest.main()
