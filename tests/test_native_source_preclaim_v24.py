"""Source-only original 306 preclaim: no implicit authorization, no signatures."""
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock,patch

ROOT=Path(__file__).resolve().parents[1]
FILE=ROOT/'ops/native-source-publisher-v1/original_306_preclaim.py'
spec=importlib.util.spec_from_file_location('_mad4b_source_preclaim_fixture',FILE)
preclaim=importlib.util.module_from_spec(spec);spec.loader.exec_module(preclaim)

class SourcePreclaimV24(unittest.TestCase):
    def setUp(self):
        self.job='a'*32
        self.plan={"contract":"mad4b.company-code-release.plan.v1",
          "company":"tours-central","environment":"staging",
          "site_uuid":"e5e64bca-ed62-48d2-b2b3-dff693cd702a",
          "origin":"https://tours.dev.mad4b.com", "changed_count":1}
        body=json.dumps({k:v for k,v in self.plan.items() if k!='changed_count'},
                         separators=(',',':'),ensure_ascii=False,sort_keys=True).encode()
        self.plan['plan_sha256']=hashlib.sha256(body).hexdigest()
        self.op='source.code.release'
        self.sha='b'*64
        self.manifest={"operation_id":self.op,"source_family":"mad4b.native-source-release.v2"}
        self.index=types.SimpleNamespace(command_sha256=lambda op,sha:self.sha)
        self.master=Mock()
        self.pub=types.SimpleNamespace(
            BINDING_KEY='source_binding',
            validate_manifest=Mock(), checked_policy=Mock(return_value={'root':'trusted'}),
            operation_binding=Mock(return_value={'operation_id':self.op}),
            check_plan=Mock(),native_runtime_binding=Mock(),
            core=types.SimpleNamespace(verify_grant=Mock()),
            company_policy=Mock(return_value={'policy':'trusted'}),
            verify_master_approval=Mock(),fresh_master_ticket_readback=Mock())
        self.grant={"original_job_id":self.job,"nonce":"original-signed-nonce"}
        self.approval={"contract":"signed_master_ticket"}
        self.original={
            'job_id':self.job,'company':self.plan['company'],
            'environment':self.plan['environment'],'site_uuid':self.plan['site_uuid'],
            'canonical_origin':self.plan['origin'],
            'operation':'company.remote.wpcli.full','target_host_id':'srv2030908',
            'broker_host_id':'srv2030908','target_scope':'wordpress',
            'job_type':'signed_vps_wpcli','command_format':'wpcli.argv.json.v1',
            'command_sha256':self.sha,'domain_intent':'operations.write',
            'state':'QUEUED_FOR_VPS_BROKER_CLAIM','executed':False,
            'grant_id':'c'*32,'transport_id':'d'*32,'expires_epoch':2000000800}
        self.response={"cas_finalizer_enrolled":True,
                       "native_dynamic_313_supported":True,"job":self.original}
        self.signed_association=Mock(return_value=(self.op,self.manifest))
        self.load=Mock(return_value=self.pub)
        self.read=Mock(side_effect=lambda p,root:
            self.grant if root==preclaim.GRANTS else self.approval)
        self.wp=Mock(return_value=self.response)
        self.trusted=types.SimpleNamespace(
            sha=lambda b:hashlib.sha256(b).hexdigest(),
            canonical=lambda v:json.dumps(v,ensure_ascii=False,sort_keys=True,
                separators=(',',':')).encode())
        self.patched=patch.dict(sys.modules,{'registry':self.trusted})
        self.patched.start();self.addCleanup(self.patched.stop)

    def call(self,plan=None,**kwargs):
        args=dict(now=2000000000,operation_resolver=self.signed_association,
            load_source=self.load,private_reader=self.read,wp_reader=self.wp)
        args.update(kwargs)
        return preclaim.verify_claimable(self.index,
            self.plan if plan is None else plan,self.job,self.sha,**args)

    def test_exact_original_305_ready_and_crypto_dependencies_called(self):
        state=self.call()
        self.assertEqual(state['contract'],'mad4b.original306.dynamic-provider-preclaim.v1')
        self.assertEqual(state['state'],'REGISTERED_PROVIDER_ORIGINAL_305_EXACT_SIGNED_PRECLAIM')
        self.assertEqual(state['operation_id'],self.op)
        self.assertTrue(state['original_313_finalizer_enrolled'])
        self.assertTrue(state['provider_release_grant_verified'])
        self.assertFalse(state['new_grant_issued'])
        self.assertFalse(state['new_job_issued'])
        self.assertFalse(state['original_313_certified'])
        self.pub.check_plan.assert_called_once()
        checked_plan=self.pub.check_plan.call_args.args[0]
        self.assertEqual(checked_plan['source_binding'],{'operation_id':self.op})
        self.assertNotIn('source_binding',self.plan)
        self.pub.core.verify_grant.assert_called_once_with(
            self.plan,{'policy':'trusted'},self.grant,'apply')
        self.pub.fresh_master_ticket_readback.assert_called_once()

    def test_unsigned_plan_binding_is_rejected_before_provider_access(self):
        invalid={**self.plan,'source_binding':{'operation_id':self.op}}
        with self.assertRaisesRegex(preclaim.PreclaimDenied,'PLAN_MUTATION'):
            self.call(invalid)
        self.signed_association.assert_not_called()
        self.load.assert_not_called()

    def test_changed_original_cas_bytes_are_rejected(self):
        invalid={**self.plan,'origin':'https://different.invalid'}
        with self.assertRaisesRegex(preclaim.PreclaimDenied,'PLAN_CHANGED'):
            self.call(invalid)
        self.signed_association.assert_not_called()

    def test_wrong_signed_operation_command_is_rejected(self):
        self.index.command_sha256=lambda op,sha:'c'*64
        with self.assertRaisesRegex(preclaim.PreclaimDenied,'COMMAND_CHANGED'):
            self.call()
        self.load.assert_not_called()

    def test_finalizer_unavailable_holds_before_grant_or_ticket(self):
        self.response['cas_finalizer_enrolled']=False
        with self.assertRaisesRegex(preclaim.PreclaimDenied,'FINALIZER_NOT_ENROLLED'):
            self.call()
        self.read.assert_not_called()
        self.pub.fresh_master_ticket_readback.assert_not_called()

    def test_wrong_original_job_or_stale_epoch_holds(self):
        self.original['command_sha256']='e'*64
        with self.assertRaisesRegex(preclaim.PreclaimDenied,'NOT_EXACTLY_QUEUED'):
            self.call()
        self.original['command_sha256']=self.sha
        self.original['expires_epoch']=2000000100
        with self.assertRaisesRegex(preclaim.PreclaimDenied,'NOT_EXACTLY_QUEUED'):
            self.call()
        self.read.assert_not_called()

    def test_invalid_signed_grant_job_id_holds_before_ticket(self):
        self.grant['original_job_id']='e'*32
        with self.assertRaisesRegex(preclaim.PreclaimDenied,'GRANT_NOT_JOB_BOUND'):
            self.call()
        self.pub.core.verify_grant.assert_not_called()
        self.pub.fresh_master_ticket_readback.assert_not_called()

    def test_unsigned_or_revoked_master_approval_does_not_return_success(self):
        self.pub.verify_master_approval.side_effect=ValueError('EXPIRED_OR_UNSIGNED')
        with self.assertRaisesRegex(ValueError,'EXPIRED_OR_UNSIGNED'):
            self.call()
        self.pub.fresh_master_ticket_readback.assert_not_called()

    def test_failed_original_runtime_binding_never_reads_wp_job(self):
        self.pub.native_runtime_binding.side_effect=ValueError('WRONG_ORIGIN')
        with self.assertRaisesRegex(ValueError,'WRONG_ORIGIN'):
            self.call()
        self.wp.assert_not_called()
        self.read.assert_not_called()

    def test_missing_root_private_grant_refused(self):
        with tempfile.TemporaryDirectory(prefix='mad4b-source-preclaim-private-') as d:
            root=Path(d)
            root.chmod(0o700)
            file=root/(self.job+'.json')
            with self.assertRaisesRegex(preclaim.PreclaimDenied,'MISSING'):
                preclaim.root_private_json(file,root)
            file.write_text(json.dumps(self.grant));file.chmod(0o644)
            with self.assertRaisesRegex(preclaim.PreclaimDenied,'UNTRUSTED'):
                preclaim.root_private_json(file,root)
            file.chmod(0o600)
            self.assertEqual(preclaim.root_private_json(file,root),self.grant)

if __name__=='__main__':unittest.main()
