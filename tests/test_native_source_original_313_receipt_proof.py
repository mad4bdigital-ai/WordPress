"""Source-only Original313 environment evidence from real-scope host receipts."""
from copy import deepcopy
from contextlib import ExitStack
from unittest.mock import patch
import json
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'ops/native-source-publisher-v1'
sys.path.insert(0,str(SRC))
spec=importlib.util.spec_from_file_location('original313_readback_fixture',SRC/'original_313_independent_readback.py')
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class ReceiptScope(unittest.TestCase):
    def setUp(self):
        self.plan={
            'environment':'staging', 'company':'demo',
            'site_uuid':'00000000-0000-4000-8000-000000000001',
            'git_commit':'a'*40, 'plan_sha256':'b'*64,
            'files':[{'target':'wp-content/plugins/mad4b-a/one.php'},
                     {'target':'wp-content/plugins/mad4b-b/two.js'}],
        }
        self.grant={'nonce':'c'*32}
        self.record={
            'contract':mod.publisher.core.RECEIPT_CONTRACT,
            'state':'APPLIED_VERIFIED',
            'company':self.plan['company'],
            'environment':self.plan['environment'],
            'site_uuid':self.plan['site_uuid'],
            'git_commit':self.plan['git_commit'],
            'plan_sha256':self.plan['plan_sha256'],
            'nonce':self.grant['nonce'],
            'files':[row['target'] for row in self.plan['files']],
            'failed_reason':None,
            'broker_grant_signature_verified':True,
            'broker_receipt_verified':False,
            'original_313_receipt':False,
            'at':'2026-11-12T18:00:00+00:00',
        }

    def test_staging_produces_false_after_verification(self):
        self.assertIs(mod.receipt_bound_production_mutation(self.plan,self.record,self.grant),False)

    def test_production_requires_matching_receipt_and_returns_true(self):
        self.plan['environment']='production'
        self.record['environment']='production'
        self.assertIs(mod.receipt_bound_production_mutation(self.plan,self.record,self.grant),True)
        self.record['environment']='staging'
        with self.assertRaises(mod.publisher.Denied):
            mod.receipt_bound_production_mutation(self.plan,self.record,self.grant)

    def test_any_receipt_or_scope_mismatch_denied(self):
        mismatches={
          'contract':'other', 'state':'PREPARED', 'company':'foreign',
          'environment':'production', 'site_uuid':'00000000-0000-4000-8000-000000000002',
          'git_commit':'d'*40, 'plan_sha256':'0'*64, 'nonce':'f'*32,
          'files':[], 'failed_reason':'SOMETHING_FAILED',
          'broker_grant_signature_verified':False, 'broker_receipt_verified':True,
          'original_313_receipt':True,
        }
        for field,bad in mismatches.items():
            with self.subTest(field=field):
                record=deepcopy(self.record);record[field]=bad
                with self.assertRaises(mod.publisher.Denied):
                    mod.receipt_bound_production_mutation(self.plan,record,self.grant)

    def test_plan_and_grant_cannot_inject_environment_or_nonce(self):
        for bad in ('production ', None, True, 'development'):
            plan=dict(self.plan,environment=bad)
            with self.subTest(environment=bad),self.assertRaises(mod.publisher.Denied):
                mod.receipt_bound_production_mutation(plan,self.record,self.grant)
        plan=dict(self.plan,files=[])
        with self.assertRaises(mod.publisher.Denied):
            mod.receipt_bound_production_mutation(plan,self.record,self.grant)
        with self.assertRaises(mod.publisher.Denied):
            mod.receipt_bound_production_mutation(self.plan,self.record,dict(nonce='f'*32))
        with self.assertRaises(mod.publisher.Denied):
            mod.receipt_bound_production_mutation(self.plan,self.record,None)

    def test_original_job_evidence_flow_only_after_receipt_and_live_hash(self):
        # All real authority/IO functions are stubbed: this is a source-only
        # contract test, not an Original312 approval or signed Original313.
        import hashlib
        blob=b'<?php echo "checked";'
        digest=hashlib.sha256(blob).hexdigest()
        plan=deepcopy(self.plan)
        plan['environment']='production'
        plan['origin']='https://example.invalid'
        plan['target_root']='/tmp/mad4b-isolated-fixture'
        plan['files']=[{'source':'wp-content/plugins/mad4b-fixture/verified.php',
                        'target':'wp-content/plugins/mad4b-fixture/verified.php',
                        'source_sha256':digest}]
        record=deepcopy(self.record)
        record['environment']='production'
        record['files']=[plan['files'][0]['target']]
        grant=dict(self.grant,original_job_id='b'*32)
        signed=dict(grant_id='signed-grant-id',transport_id='signed-transport-id')
        def run(record_data,live_blob=blob):
            with ExitStack() as stack:
                for target,attribute,result in (
                    (mod.publisher,'check_plan',None),
                    (mod.handler,'original_312_for_claim',signed),
                    (mod.publisher,'company_policy',{}),
                    (mod.publisher.core,'verify_grant',None),
                    (mod.publisher,'native_runtime_binding',None),
                    (mod.publisher.core,'assert_pinned_receipt_directory',None),
                    (mod.handler,'protected_file',json.dumps(record_data).encode()),
                    (mod.publisher.core,'no_link',None),
                    (mod.publisher.core,'safe_regular',live_blob),
                ):
                    stack.enter_context(patch.object(target,attribute,return_value=result))
                return mod.evidence_for_original_job('b'*32,plan,
                    {'receipt_dir':'/tmp/no-access-needed'},grant)
        payload=run(record)
        self.assertIs(payload['production_mutation'],True)
        self.assertEqual(payload['grant_id'],signed['grant_id'])
        self.assertEqual(payload['transport_id'],signed['transport_id'])
        self.assertEqual(payload['readback_sha256'],payload['expected_readback_sha256'])
        self.assertEqual(payload['output_sha256'],payload['readback_sha256'])
        mismatch=deepcopy(record)
        mismatch['company']='foreign'
        with self.assertRaises(mod.publisher.Denied):
            run(mismatch)
        with self.assertRaises(mod.publisher.Denied):
            run(record,b'changed target')

    def test_pure_function_no_signing_or_site_writes(self):
        source=(SRC/'original_313_independent_readback.py').read_text()
        for unsafe in ('Ed25519PrivateKey','issue_grant(', 'sign_proof(', 'atomic_write('):
            self.assertNotIn(unsafe, source)

if __name__=='__main__':unittest.main()
