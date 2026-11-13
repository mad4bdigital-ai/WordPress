"""Hermetic first-party signer/finish contract, no live 313/WordPress changes."""
from copy import deepcopy
import base64
import importlib.util
import json
import hashlib
import tempfile
import subprocess
from pathlib import Path
import sys
import types
import unittest
import os
from unittest import mock
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
ROOT=Path(__file__).resolve().parents[1]
DIR=ROOT/'ops/native-source-publisher-v1'
sys.path.insert(0,str(DIR))
spec=importlib.util.spec_from_file_location('_mad4b_original313_source_v27',DIR/'original_313_source_finalizer.py')
source=importlib.util.module_from_spec(spec);spec.loader.exec_module(source)

class OfflineIndex:
    def __init__(self,manifest,schema):
        self.manifest,self.schema=manifest,schema
    def evidence_definition(self,op):
        assert op==self.manifest['operation_id']
        return self.schema

class FirstPartyOriginal313V27(unittest.TestCase):
    def setUp(self):
        self.schema=json.loads((DIR/'catalog/evidence-schema.json').read_text())
        self.manifest=json.loads((DIR/'catalog/generated/source.code.release.manifest.json').read_text())
        self.operation=self.manifest['operation_id']
        self.idx=OfflineIndex(self.manifest,self.schema)
        self.job='a'*32
        self.digest='b'*64
        self.plan_sha='c'*64
        self.native_sha='d'*64
        argv=[*self.manifest['command']['prefix'],
              self.manifest['command']['flag']+self.plan_sha]
        self.command=hashlib.sha256(json.dumps(argv,separators=(',',':'),
            ensure_ascii=False).encode()).hexdigest()
        self.auth={'grant_id':'f'*32,'transport_id':'1'*32,'command_sha256':self.command}
        self.plan={'contract':'mad4b.company-code-release.plan.v1',
            'plan_sha256':self.plan_sha,'company':'tours-central',
            'environment':'staging','site_uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
            'origin':'https://tours.dev.mad4b.com'}
        self.payload={'result_status':'HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313',
            'output_sha256':self.digest,'readback_sha256':self.digest,
            'expected_readback_sha256':self.digest,
            'grant_id':self.auth['grant_id'],'transport_id':self.auth['transport_id'],
            'production_mutation':False,'target_sha256':self.digest,'file_count':2}
        self.observed={
            'index':self.idx,'manifest':self.manifest,'plan':self.plan,
            'authority':self.auth,'operation_id':self.operation,
            'original_job_id':self.job,'command_sha256':self.command,
            'evidence':self.payload,'native_receipt_sha256':self.native_sha,
            'readback_after_original312':True}
        self.private=Ed25519PrivateKey.generate()  # disposable test-only fixture
        self.registry=types.SimpleNamespace(
            sha=lambda x:hashlib.sha256(x).hexdigest(),
            canonical=lambda x:json.dumps(x,sort_keys=True,
              separators=(',',':'),ensure_ascii=False).encode())
        def type_check(payload,schema):
            assert set(payload)==set(schema)
            for field,v in schema.items():
                item=payload[field]
                if v=='sha256':assert isinstance(item,str) and len(item)==64
                elif v=='boolean':assert type(item) is bool
                elif v=='integer':assert type(item) is int
                elif v=='string':assert isinstance(item,str) and item
        self.evidence=types.SimpleNamespace(validate_payload=type_check)
        patch=mock.patch.dict(sys.modules,{'registry':self.registry,'evidence':self.evidence})
        patch.start();self.addCleanup(patch.stop)

    def signed(self,observed=None):
        x=source.make_signed_dynamic_proof(
            self.observed if observed is None else observed,self.private,
            issued=2000000000,nonce='2'*32)
        y=source.make_signed_native_v4(x,self.observed,self.private,
            issued=2000000000,nonce='3'*32)
        return x,y

    def test_dynamic_and_native_v4_use_existing_key_contract_without_signer_install(self):
        dynamic,v4=self.signed()
        self.assertEqual(set(dynamic),{
          'contract','job_id','operation_id','provider_version','evidence_schema',
          'company','environment','site_uuid','origin','target_id','command_sha256',
          'plan_sha256','evidence_sha256','payload','issued_epoch','expires_epoch',
          'nonce','signature'})
        tmp=dict(dynamic);sig=base64.b64decode(tmp.pop('signature'))
        self.private.public_key().verify(sig,self.registry.canonical(tmp))
        self.assertEqual(v4['observed_operation'],'source.code.release')
        self.assertEqual(v4['postcondition_key'],'source_code_release.v1')
        self.assertEqual(v4['dynamic_evidence_sha256'],dynamic['evidence_sha256'])
        self.assertEqual(v4['native_receipt_sha256'],self.native_sha)
        self.assertEqual(v4['production_mutation'],'false')
        self.assertEqual(list(v4)[:-1],source.NATIVE_V4_FIELDS)
        raw='\n'.join(str(v4[k]) for k in source.NATIVE_V4_FIELDS).encode()
        self.private.public_key().verify(base64.b64decode(v4['signature']),raw)

    def test_first_party_wordpress_verifies_exact_python_signed_proof(self):
        # The disposable private key never leaves this Python process; PHP
        # receives only the signed public evidence and a test public key.
        dynamic,_v4=self.signed()
        root_payload={
          'trusted':{'registry_contract':'mad4b.dynamic-operation-index.v1',
            'first_party_enrolled':True,'provider':self.manifest,
            'evidence_schema':self.schema,
            'independent_verifier_public_key_b64':base64.b64encode(
              self.private.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
            ).decode()},
          'proof':dynamic,
          'original_job':{'job_id':self.job,'target_host_id':source.HOST,
            'command_sha256':self.command,'operation':'company.remote.wpcli.full',
            'job_type':'signed_vps_wpcli','target_scope':'wordpress',
            'company':self.plan['company'],'environment':self.plan['environment'],
            'site_uuid':self.plan['site_uuid'],'origin':self.plan['origin']},
          'original312':{'contract':'mad4b.broker-exact-ssh-authority.v1',
            'verified':True,'eligible':True,'owner_enrolled_wpcli':True,
            'central_scope_only':True,'shell_executed':False,
            'expires_epoch':2000000500,'target_id':source.HOST,
            'grant_id':self.auth['grant_id'],
            'transport_id':self.auth['transport_id'],
            'job_id':self.job,'company':self.plan['company'],
            'environment':self.plan['environment'],
            'site_uuid':self.plan['site_uuid'],'origin':self.plan['origin'],
            'command_sha256':self.command},
        }
        with tempfile.TemporaryDirectory(prefix='mad4b-v27-php-original313-') as t:
            file=Path(t)/'source-original313-proof.json'
            file.write_text(json.dumps(root_payload))
            cmd=['docker','run','--rm','--pull=never','--network','none',
              '--read-only','--cap-drop=ALL','--security-opt=no-new-privileges',
              '-v',str(ROOT)+':/work:ro','-v',t+':/fixture:ro',
              '--entrypoint','php',os.environ.get('MAD4B_TEST_PHP_IMAGE','php:8.5-cli'),
              '/work/tests/native-source-original313-signed-proof-v27.php']
            result=subprocess.run(cmd,capture_output=True,text=True,timeout=35)
            self.assertEqual(result.returncode,0,result.stderr[-700:]+result.stdout[-700:])
            response=json.loads(result.stdout)
            self.assertEqual(response['state'],'PASS')
            self.assertTrue(response['signed_dynamic_proof_verified'])
            self.assertFalse(response['actual_original313_receipt_issued'])
            self.assertFalse(response['production_site_modified'])

    def test_unsigned_changed_output_never_reaches_signer(self):
        for key,bad in (('output_sha256','9'*64),
                        ('production_mutation',True),('readback_sha256','8'*64),
                        ('grant_id','0'*32)):
            observed=deepcopy(self.observed)
            observed['evidence'][key]=bad
            with self.subTest(key=key),self.assertRaises(source.FinalizerHold):
                source.make_signed_dynamic_proof(observed,self.private)

    def test_proof_without_original312_receipt_is_denied(self):
        observed=deepcopy(self.observed)
        observed['readback_after_original312']=False
        with self.assertRaises(source.FinalizerHold):
            source.make_signed_dynamic_proof(observed,self.private)

    def test_native_v4_must_match_the_exact_signed_dynamic_evidence(self):
        signed,native=self.signed()
        bad=dict(signed,plan_sha256='9'*64)
        with self.assertRaises(source.FinalizerHold):
            source.make_signed_native_v4(bad,self.observed,self.private)
        self.assertEqual(native['dynamic_evidence_sha256'],signed['evidence_sha256'])

    def test_unsigned_preflight_does_not_import_or_use_original_signer(self):
        with mock.patch.object(source,'readback_approved_original',return_value=self.observed):
            with mock.patch.object(source,'original_finalizer_module') as legacy:
                state=source.finalize(self.job,self.plan_sha,read_only=True)
        self.assertEqual(state['state'],'EXACT_SOURCE_INDEPENDENT_POSTIMAGE_READY_UNSIGNED')
        self.assertFalse(state['original_313_certified'])
        self.assertFalse(state['signed_original_313'])
        legacy.assert_not_called()

    def test_source_not_enrolled_denies_before_original313_key_lookup(self):
        legacy=types.SimpleNamespace(wp_manager_command=mock.Mock(return_value={
            'ready':False,'native_v4':True}),native_signer=mock.Mock())
        with mock.patch.object(source,'readback_approved_original',return_value=self.observed):
            with mock.patch.object(source,'original_finalizer_module',return_value=legacy):
                with self.assertRaisesRegex(source.FinalizerHold,'WORDPRESS_SIGNED313_NOT_READY'):
                    source.finalize(self.job,self.plan_sha,read_only=False)
        legacy.native_signer.assert_not_called()

    def test_incompatible_original_job_refuses_any_proof_ingest(self):
        dp,v4=self.signed()
        legacy=types.SimpleNamespace(original_central_state=mock.Mock(
            return_value={'job_state':'FINISHED_WITH_WRONG_SCOPE','job_executed':True}),
            wp_manager_command=mock.Mock(),original_module=mock.Mock())
        with self.assertRaisesRegex(source.FinalizerHold,'ORIGINAL_JOB_NOT_PENDING'):
            source.finalization_once(self.observed,dp,v4,legacy=legacy)
        legacy.wp_manager_command.assert_not_called()
        legacy.original_module.assert_not_called()

    def test_independent_signed_finish_pipeline_uses_existing_central_services(self):
        dp,v4=self.signed()
        pending=mock.Mock()
        helper=types.SimpleNamespace(
            original_signer=mock.Mock(return_value=self.private),
            check_original_wordpress_signer=mock.Mock())
        def manager(args):
            if args[:3]==['mad4b','dynamic-313-proof','ingest']:
                return {'verified':True,'state':'INDEPENDENT_DYNAMIC_EVIDENCE_STORED','job_id':self.job}
            if args[:3]==['mad4b','native-313-proof','ingest']:
                return {'verified':True,'state':'INDEPENDENT_NATIVE_HOST_PROOF_STORED','job_id':self.job}
            if args[:3]==['mad4b','broker-remote-ssh','wpcli-finish']:
                return {'verified':True,'state':'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED',
                        'job_id':self.job,'native_receipt_sha256':self.native_sha}
            raise AssertionError('NO_NEW_HOST_EXECUTOR')
        legacy=types.SimpleNamespace(
            original_central_state=mock.Mock(return_value={
               'job_state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
               'job_executed':False}),
            original_module=mock.Mock(side_effect=lambda path,name:
                helper if path==source.ORIGINAL_SIGNER else
                types.SimpleNamespace(send_signed_pending=pending)),
            wp_manager_command=mock.Mock(side_effect=manager),
            store_original_finish_once=mock.Mock(side_effect=lambda p,d:d))
        result=source.finalization_once(self.observed,dp,v4,legacy=legacy)
        self.assertTrue(result['original_313_certified'])
        self.assertFalse(result['host_execution_replayed'])
        self.assertFalse(result['new_grant_created'])
        self.assertEqual(legacy.wp_manager_command.call_count,3)
        pending.assert_called_once()
        path=legacy.store_original_finish_once.call_args.args[0]
        self.assertTrue(path.name.endswith('-native-source313-finish.json'))
        self.assertNotIn('resource-cas313',path.name)

    def test_idempotent_matching_original313_readback_never_signs_or_posts(self):
        dp,v4=self.signed()
        state={
            'job_state':'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED',
            'job_executed':True,'original_readback_verified':True,
            'pending_native_certified':True,'pending_native_sha256':self.native_sha,
            'finished_native_sha256':self.native_sha,'signed_dynamic_verified':True,
            'signed_dynamic_plan':self.plan_sha,
            'signed_dynamic_evidence':dp['evidence_sha256'],
            'signed_native_contract':'mad4b.native-313-independent-host-proof.v4',
            'signed_native_receipt':self.native_sha,
            'signed_native_plan':self.plan_sha,
            'signed_native_evidence':dp['evidence_sha256']}
        legacy=types.SimpleNamespace(original_central_state=mock.Mock(return_value=state),
          original_module=mock.Mock(),wp_manager_command=mock.Mock())
        result=source.finalization_once(self.observed,dp,v4,legacy=legacy)
        self.assertTrue(result['already_certified'])
        self.assertFalse(result['host_execution_replayed'])
        legacy.original_module.assert_not_called()
        legacy.wp_manager_command.assert_not_called()

    def test_previous_native_receipt_mismatch_is_a_hard_hold(self):
        dp,v4=self.signed()
        legacy=types.SimpleNamespace(
            original_central_state=mock.Mock(return_value={
              'job_state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
              'job_executed':False,'pending_state':'PENDING_INDEPENDENT_NATIVE_READBACK',
              'pending_grant_id':self.auth['grant_id'],
              'pending_command_sha256':self.command,
              'pending_output_sha256':'0'*64,
              'pending_readback_sha256':self.digest}),
            original_module=mock.Mock(),wp_manager_command=mock.Mock())
        # The first-party Broker signer is loaded before checking an existing
        # pending state, but no proof is ingested and no finish occurs.
        fake_broker=types.SimpleNamespace(original_signer=lambda:self.private,
                          check_original_wordpress_signer=lambda s:None)
        legacy.original_module.side_effect=lambda path,name:fake_broker
        with self.assertRaisesRegex(source.FinalizerHold,'EXISTING_PENDING_CHANGED'):
            source.finalization_once(self.observed,dp,v4,legacy=legacy)
        legacy.wp_manager_command.assert_not_called()

if __name__=='__main__':unittest.main()
