"""The source publisher's disabled template must never become stale authority."""
from copy import deepcopy
from pathlib import Path
import importlib.util
import json
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
FOLDER=ROOT/'ops/native-source-publisher-v1'
CATALOG=FOLDER/'catalog'
spec=importlib.util.spec_from_file_location('mad4b_source_catalog_v20',FOLDER/'build_enrollment_candidate.py')
builder=importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

class SourceEnrollmentV20(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='mad4b-source-enrollment-v20-')
        self.addCleanup(self.temp.cleanup)
        self.catalog=Path(self.temp.name)
        self.template=json.loads((CATALOG/'policy-template.DISABLED.json').read_text())
        self.proposal=json.loads((CATALOG/'publisher-enrollment-proposal.json').read_text())
        self.write()

    def write(self):
        (self.catalog/'policy-template.DISABLED.json').write_text(json.dumps(self.template))
        (self.catalog/'publisher-enrollment-proposal.json').write_text(json.dumps(self.proposal))

    def check(self):
        return builder.validate_enrollment_documents(self.catalog)

    def test_source_only_contract_is_aligned_with_runtime(self):
        value=self.check()
        self.assertEqual(value['state'],'SOURCE_ONLY_ENROLLMENT_CONTRACTS_ALIGNED')
        self.assertFalse(value['policy_enabled'])
        self.assertFalse(value['registry_enrolled'])
        self.assertFalse(value['broker_or_master_grant_created'])

    def test_old_policy_schema_rejected_before_manifest_generation(self):
        self.template['contract']='mad4b.native-source-publisher-policy.v1'
        self.write()
        with self.assertRaisesRegex(ValueError,'TEMPLATE_CONTRACT_DRIFT'):self.check()

    def test_stale_approval_signature_contract_rejected(self):
        self.proposal['approval_contract']='mad4b.native-source-master-approval.v1'
        self.write()
        with self.assertRaisesRegex(ValueError,'PROPOSAL_CONTRACT_DRIFT'):self.check()

    def test_undeclared_or_changed_operation_rejected(self):
        self.proposal['operation_id']='arbitrary.source.mutation'
        self.write()
        with self.assertRaisesRegex(ValueError,'OPERATION_BINDING_DRIFT'):self.check()

    def test_site_and_target_policy_binding_is_immutable(self):
        self.template['targets']['tours-central:staging']['site_uuid']='12345678-1234-4123-8123-123456789abc'
        self.write()
        with self.assertRaisesRegex(ValueError,'SITE_OR_TARGET_DRIFT'):self.check()

    def test_undocumented_activation_or_preloaded_public_keys_rejected(self):
        self.template['enabled']=True
        self.write()
        with self.assertRaisesRegex(ValueError,'TEMPLATE_CONTRACT_DRIFT'):self.check()
        self.template['enabled']=False
        self.proposal['publisher_enabled']=True
        self.write()
        with self.assertRaisesRegex(ValueError,'PROPOSAL_CONTRACT_DRIFT'):self.check()
        self.proposal['publisher_enabled']=False
        self.template['broker_public_key_b64']='AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
        self.write()
        with self.assertRaisesRegex(ValueError,'TEMPLATE_CONTRACT_DRIFT'):self.check()

    def test_symlink_policy_template_rejected(self):
        (self.catalog/'policy-template.DISABLED.json').unlink()
        (self.catalog/'policy-template.DISABLED.json').symlink_to(CATALOG/'policy-template.DISABLED.json')
        with self.assertRaisesRegex(ValueError,'DOCUMENT_UNTRUSTED'):self.check()

if __name__=='__main__':unittest.main()
