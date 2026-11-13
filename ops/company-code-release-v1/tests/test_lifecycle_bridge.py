import ast
import importlib.util
import pathlib
import tempfile
from unittest.mock import patch
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("lifecycle_bridge",ROOT/"company_lifecycle_broker.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)


class OriginalLifecycleBridgeTests(unittest.TestCase):
    def setUp(self):
        self.enroll={
            "contract":"mad4b.company-native-wpcli-enrollment.v1",
            "state":"ORIGINAL_305_PERSISTENT_PAIR_ENROLLED",
            "company":"wayfatours","environment":"production",
            "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c","host_authority_verified":True,
            "full_wpcli_active":True,"persistent_grant_present":True,
            "execution_performed":False,
        }
        self.verify={
            "contract":"mad4b.broker-exact-ssh-authority.v1",
            "verifier_registered":True,
            "remote_execution_authority_automatically_granted":False,
            "shell_executed":False,
        }
        self.dispatch={"dispatch_registered":False}
    def report(self,ops=("read","plugin.install")):
        return m.reconcile(
            self.enroll,self.verify,self.dispatch,set(ops),
            company="wayfatours",environment="production",
            site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05c",
            origin="https://wayfatours.com",
        )
    def test_standing_305_not_equivalent_to_code_release_authority(self):
        x=self.report()
        self.assertTrue(x["original_305_standing_wpcli_verified"])
        self.assertTrue(x["original_312_verifier_registered"])
        self.assertFalse(x["code_release_execution_allowed"])
        self.assertFalse(x["production_mutated"])
        self.assertFalse(x["legacy_staging_ssh_installation_is_authoritative"])
        self.assertIn("ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED",x["blockers"])
    def test_readiness_does_not_mint_dispatch(self):
        x=self.report()
        self.assertFalse(x["company_code_release_dispatch_ability_registered"])
        self.assertIn("COMPANY_CODE_RELEASE_DISPATCH_ABILITY_NOT_REGISTERED",x["blockers"])
    def test_live_dynamic_registry_is_distinguished_from_legacy_handler(self):
        x=m.reconcile(
            self.enroll,self.verify,self.dispatch,{"read","plugin.install"},
            company="wayfatours",environment="production",
            site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05c",
            origin="https://wayfatours.com",
            dynamic={
                "dynamic_registry_enrolled":True,
                "dynamic_scope_plan_matched":True,
                "dynamic_306_digest_registered":True,
                "dynamic_313_independent_verifier_registered":True,
                "dynamic_313_finalizer_connected":False,
            })
        self.assertTrue(x["dynamic_306_digest_registered"])
        self.assertTrue(x["dynamic_313_independent_verifier_registered"])
        self.assertFalse(x["dynamic_313_finalizer_connected"])
        self.assertEqual(x["state"],"HOLD_DYNAMIC_ORIGINAL_313_FINALIZER")
        self.assertFalse(x["code_release_execution_allowed"])
        self.assertIn("DYNAMIC_313_ORIGINAL_FINALIZER_NOT_CONNECTED",
                      x["blockers"])
        self.assertNotIn("ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED",
                         x["blockers"])

    def test_central313_observed_without_registered_306_plan_is_not_dispatch(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-central313-readonly-') as temp:
            with patch.object(m,'DYNAMIC_ROOT',pathlib.Path(temp)), \
                 patch.object(m,'central_313_status',return_value={
                    'independent_verifier_ready':True,
                    'original_313_finalizer_connected':True,
                    'operational_ready':True}):
                observed=m.dynamic_status('wayfatours','production','a'*64)
        self.assertTrue(observed['central_313_status_observed'])
        self.assertTrue(observed['central_313_operational_ready'])
        self.assertTrue(observed['central_313_finalizer_configured'])
        self.assertFalse(observed['dynamic_scope_plan_matched'])
        self.assertFalse(observed['dynamic_306_digest_registered'])
        self.assertFalse(observed['dynamic_313_finalizer_connected'])
        report=m.reconcile(self.enroll,self.verify,self.dispatch,
             {'read','plugin.install'},company='wayfatours',
             environment='production',origin='https://wayfatours.com',
             site_uuid=self.enroll['site_uuid'],dynamic=observed)
        self.assertTrue(report['central_313_operational_ready'])
        self.assertFalse(report['code_release_execution_allowed'])
        self.assertFalse(report['original_signed_job_grant_verified'])
        self.assertIn('ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED',
                      report['blockers'])

    def test_central313_unavailable_is_not_assumed_ready(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-central313-down-') as temp:
            with patch.object(m,'DYNAMIC_ROOT',pathlib.Path(temp)), \
                 patch.object(m,'central_313_status',side_effect=RuntimeError('UNAVAILABLE')):
                observed=m.dynamic_status('wayfatours','production')
        self.assertFalse(observed['central_313_status_observed'])
        self.assertFalse(observed['central_313_operational_ready'])
        self.assertFalse(observed['dynamic_313_finalizer_connected'])

    def test_central313_scope_requires_current_first_party_and_no_fake_receipt(self):
        fixture={'contract':'mad4b.dynamic-313-wordpress.v1',
          'first_party_proof_enrolled':True,'operation_id':'company.code.release',
          'job_issued':False,'grant_issued':False,'original_313_receipt_issued':False,
          'production_mutated':False,'independent_verifier_ready':True,
          'original_313_finalizer_connected':True,'operational_ready':True}
        x=m.central_313_status(central_reader=lambda:fixture)
        self.assertTrue(x['operational_ready'])
        for key,value in [('operation_id','plugin.install'),
                          ('first_party_proof_enrolled',False),
                          ('job_issued',True),('grant_issued',True),
                          ('original_313_receipt_issued',True),
                          ('production_mutated',True)]:
            with self.subTest(key=key):
                with self.assertRaises(RuntimeError):
                    m.central_313_status(central_reader=lambda:{**fixture,key:value})

    def test_305_wrong_uuid_denied(self):
        self.enroll["site_uuid"]="00000000-0000-0000-0000-000000000000"
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])
    def test_305_wrong_environment_denied(self):
        self.enroll["environment"]="staging"
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])
    def test_305_standing_disabled_denied(self):
        self.enroll["full_wpcli_active"]=False
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])
    def test_312_must_be_original_no_auto_grant(self):
        self.verify["remote_execution_authority_automatically_granted"]=True
        self.assertFalse(self.report()["original_312_verifier_registered"])
    def test_even_operation_and_dispatch_presence_require_real_job_and_receipt(self):
        self.dispatch={"dispatch_registered":True}
        x=self.report(("read","plugin.install",m.RELEASE_OPERATION))
        self.assertTrue(x["original_313_code_release_handler_registered"])
        self.assertTrue(x["company_code_release_dispatch_ability_registered"])
        self.assertFalse(x["code_release_execution_allowed"])
        self.assertFalse(x["original_signed_job_grant_verified"])
    def test_company_scope_mismatch_is_not_verified(self):
        self.enroll["company"] = "another-company"
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])

    def test_qa_company_status_command_is_accepted(self):
        command=m.command305("wayfatours","qa")
        self.assertIn("--environment=qa",command)
    def test_shell_text_in_environment_rejected(self):
        with self.assertRaises(ValueError):
            m.command305("wayfatours","qa;echo unsafe")

    def test_operation_scope_command_input_is_bounded(self):
        with self.assertRaises(ValueError):
            m.command305("wayfatours;touch /tmp/pwned","production")
        with self.assertRaises(ValueError):
            m.command_dispatch("container;echo unsafe")
        command=m.command305("wayfatours","production")
        self.assertIn("--company=wayfatours",command)

    def test_original_handler_ast_enumeration(self):
        with tempfile.TemporaryDirectory() as temp:
            f=pathlib.Path(temp)/"handler.py"
            f.write_text("p.add_argument('--operation',choices=['read','plugin.install'])")
            self.assertEqual(m.original_handler_operations(f),{"read","plugin.install"})
            f.write_text("p.add_argument('--other',choices=['code.release'])")
            with self.assertRaises(RuntimeError):
                m.original_handler_operations(f)


if __name__=="__main__":
    unittest.main()
